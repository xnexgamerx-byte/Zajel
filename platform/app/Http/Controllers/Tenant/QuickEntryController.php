<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Support\Arabic;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * الإدخال السريع: جدولٌ حتى ثلاثين صفّاً، كما في «خلق شحنة جديدة – على أساس
 * المتجر» و«على مستوى المحافظة» في المعتاد. التاجر (أو المحافظة) أعلى الصفحة
 * مرّةً واحدة، وفي كل صفٍّ ما يختلف: المبلغ بالألف، والهاتف، والمنطقة، والعنوان.
 *
 * الجدول يُحفظ كلّه أو لا يُحفظ منه شيء: صفٌّ خاطئ يُصحَّح في مكانه والباقي
 * كما كُتب، فلا يُعاد إدخال ما حُفظ فيتكرّر.
 */
class QuickEntryController extends Controller
{
    public const MAX_ROWS = 30;

    /** المبلغ بالألف: «25» خمسةٌ وعشرون ألفاً؛ وما بلغ هذا فالأرجح أنه كُتب كاملاً. */
    public const THOUSANDS_CEILING = 10_000;

    public function create(Request $request): View
    {
        $mode = $request->query('mode') === 'governorate' ? 'governorate' : 'merchant';

        return view('tenant.shipments.quick', [
            'mode'         => $mode,
            'merchants'    => Merchant::where('status', 'active')->orderBy('business_name')->get(['id', 'business_name', 'phone']),
            'governorates' => Governorate::where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar']),
            'cities'       => City::where('is_active', true)->orderBy('name_ar')->get(['id', 'governorate_id', 'name_ar']),
            'couriers'     => Courier::delivering()->active()->orderBy('name')->get(['id', 'name']),
            'baghdad'      => Governorate::where('code', 'BGD')->value('id'),
            'rows'         => max(5, min(self::MAX_ROWS, count((array) old('rows', [])))),
        ]);
    }

    public function store(Request $request, CreateShipment $create, ChangeShipmentStatus $change): RedirectResponse
    {
        $header = $request->validate([
            'mode'           => ['required', 'in:merchant,governorate'],
            'merchant_id'    => ['nullable', 'required_if:mode,merchant', 'integer'],
            'governorate_id' => ['nullable', 'required_if:mode,governorate', 'integer',
                                 Rule::exists('governorates', 'id')->where('is_active', true)],
            'courier_id'     => ['nullable', 'integer'],
            'fees_paid_by'   => ['required', 'in:merchant,customer'],
            'rows'           => ['required', 'array', 'max:'.self::MAX_ROWS],
        ], [
            'merchant_id.required_if'    => 'اختر التاجر أعلى الصفحة.',
            'governorate_id.required_if' => 'اختر المحافظة أعلى الصفحة.',
            'rows.max'                   => 'ثلاثون صفّاً في المرّة الواحدة.',
        ], ['merchant_id' => 'التاجر', 'governorate_id' => 'المحافظة', 'courier_id' => 'المندوب']);

        $courier = null;

        if (filled($header['courier_id'] ?? null)) {
            abort_unless($request->user()->can('shipments.assign'), 403);
            $courier = Courier::delivering()->active()->find($header['courier_id']);

            if (! $courier) {
                return back()->withInput()->withErrors(['courier_id' => 'المندوب غير موجود أو غير مفعّل.']);
            }
        }

        $merchants = Merchant::where('status', 'active')->pluck('id')->flip();
        $cities = City::where('is_active', true)->get(['id', 'governorate_id'])->keyBy('id');
        $governorates = Governorate::where('is_active', true)->pluck('id')->flip();

        if ($header['mode'] === 'merchant' && ! $merchants->has((int) $header['merchant_id'])) {
            return back()->withInput()->withErrors(['merchant_id' => 'التاجر غير موجود أو موقوف.']);
        }

        $shipments = [];
        $errors = [];

        foreach ($header['rows'] as $i => $row) {
            $row = array_map(fn ($v) => is_string($v) ? trim($v) : $v, (array) $row);

            // الصفّ الفارغ يُترك: الجدول يُفتح بصفوفٍ أكثر ممّا يُملأ
            if (collect($row)->except(['governorate_id', 'exchange'])->filter(fn ($v) => filled($v))->isEmpty()) {
                continue;
            }

            $at = fn (string $field) => "rows.{$i}.{$field}";
            $data = [];

            $merchantId = $header['mode'] === 'merchant' ? (int) $header['merchant_id'] : (int) ($row['merchant_id'] ?? 0);
            if (! $merchants->has($merchantId)) {
                $errors[$at('merchant_id')] = 'اختر التاجر.';
            }

            $governorateId = $header['mode'] === 'governorate' ? (int) $header['governorate_id'] : (int) ($row['governorate_id'] ?? 0);
            if (! $governorates->has($governorateId)) {
                $errors[$at('governorate_id')] = 'اختر المحافظة.';
            }

            $cityId = filled($row['city_id'] ?? null) ? (int) $row['city_id'] : null;
            if ($cityId !== null && ($cities[$cityId]->governorate_id ?? null) !== $governorateId) {
                $errors[$at('city_id')] = 'المنطقة ليست في هذه المحافظة.';
            }

            $phone = Phone::normalise($row['recipient_phone'] ?? null);
            if ($phone === null) {
                $errors[$at('recipient_phone')] = 'هاتفٌ عراقيّ: 07 ثم تسعة أرقام.';
            }

            $amount = static::thousands($row['amount'] ?? null);
            if ($amount === null) {
                $errors[$at('amount')] = 'المبلغ بالألف: 25 لخمسةٍ وعشرين ألفاً، و0 للمدفوع مسبقاً.';
            }

            $address = (string) ($row['address'] ?? '');
            if (mb_strlen($address) < 3) {
                $errors[$at('address')] = 'اكتب العنوان: المنطقة والشارع وأقرب نقطة دالّة.';
            }

            $shipments[$i] = [
                'merchant_id'        => $merchantId,
                'recipient_name'     => filled($row['recipient_name'] ?? null) ? mb_substr($row['recipient_name'], 0, 160) : 'الزبون',
                'recipient_phone'    => $phone,
                'governorate_id'     => $governorateId,
                'city_id'            => $cityId,
                'address'            => mb_substr($address, 0, 500),
                // عنوان الإدخال السريع سطرٌ واحد: هو العنوان ونقطته الدالّة معاً
                'landmark'           => mb_substr($address, 0, 255),
                'cod_amount'         => $amount,
                'fees_paid_by'       => $header['fees_paid_by'],
                'merchant_reference' => filled($row['merchant_reference'] ?? null) ? mb_substr($row['merchant_reference'], 0, 60) : null,
                'notes'              => filled($row['notes'] ?? null) ? mb_substr($row['notes'], 0, 2000) : null,
                'type'               => ! empty($row['exchange']) ? 'exchange' : 'delivery',
                'pieces_count'       => 1,
            ];
        }

        if ($shipments === [] && $errors === []) {
            return back()->withInput()->withErrors(['rows' => 'الجدول فارغ: املأ صفّاً واحداً على الأقلّ.']);
        }

        if ($errors !== []) {
            return back()->withInput()->withErrors($errors + [
                'rows' => 'في '.Arabic::count(count(array_unique(array_map(fn ($k) => explode('.', $k)[1], array_keys($errors)))),
                    ['صفٍّ واحد', 'صفّين', 'صفوف', 'صفّاً']).' ما يُصحَّح — ولم يُحفظ شيء بعد.',
            ]);
        }

        $created = DB::transaction(function () use ($shipments, $create, $change, $courier, $request) {
            $created = [];

            foreach ($shipments as $data) {
                $shipment = $create->handle($data, $request->user());

                // سلّمها التاجر في المكتب وتخرج مع المندوب مباشرة — إلّا المعلَّقة للمراجعة
                if ($courier && ! $shipment->isHeldForReview()) {
                    $change->handle($shipment, ShipmentStatus::PickedUp, $request->user(), ['note' => 'إدخال سريع']);
                    $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $request->user(), [
                        'courier_id' => $courier->id, 'note' => 'إدخال سريع',
                    ]);
                }

                $created[] = $shipment;
            }

            return $created;
        });

        $numbers = collect($created)->pluck('number');

        return redirect()
            ->route('shipments.quick', ['mode' => $header['mode']])
            // الوجبة التالية غالباً للتاجر نفسه؛ والمندوب لا يبقى، فلا تخرج الوجبة التالية معه سهواً
            ->withInput($request->only(['merchant_id', 'governorate_id', 'fees_paid_by']))
            ->with('success', 'أُنشئت '.Arabic::shipments(count($created)).': '.$numbers->first()
                .($numbers->count() > 1 ? ' … '.$numbers->last() : '')
                .($courier ? " وخرجت مع {$courier->name}." : '.'))
            ->with('created_ids', collect($created)->pluck('id')->all());
    }

    /**
     * «25» ← 25000، «25.5» ← 25500، «٢٥» ← 25000، «0» ← 0. الفارغ والمبهم null،
     * وما بلغ عشرة آلاف (عشرة ملايين دينار) يُرفض: الأرجح أنه كُتب كاملاً لا بالألف.
     */
    public static function thousands(mixed $typed): ?int
    {
        // «٫» فاصلةٌ عشرية و«٬» فاصل آلاف؛ والفاصلة اللاتينية مبهمة («1,500» ألفٌ ونصف أم مليونٌ ونصف؟) فتُرفض
        $text = str_replace(['٫', '٬'], ['.', ''], Phone::latinDigits(trim((string) $typed)));

        if ($text === '' || ! preg_match('/^\d+(\.\d{1,3})?$/', $text)) {
            return null;
        }

        $value = (float) $text;

        return $value >= self::THOUSANDS_CEILING ? null : (int) round($value * 1000);
    }
}
