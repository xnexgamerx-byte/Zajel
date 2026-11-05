<?php

namespace App\Http\Controllers;

use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Models\AggregatorLink;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Support\FeatureGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * وصل الشحنة: ملصقٌ يُلصَق على الطرد، يقرؤه المندوب ويمسحه المخزن.
 *
 * ملصق ١٠×١٥ سم (مقاس طابعات الملصقات الحرارية)، أو أربعةٌ في ورقة A4 —
 * والملصق ربع A4 بالضبط، فلا يُقصّ شيء. عليه الباركود (رقم الوصل أو باركود
 * الوصل المطبوع مسبقاً) ورمز QR لصفحة التتبّع.
 *
 * يطبعه الموظّف لما يراه من الشحنات، والتاجر لشحناته وحده.
 */
class ShipmentLabelController extends Controller
{
    /** حدٌّ للدفعة الواحدة: ثلاثمئة ملصق نحو خمسٍ وسبعين ورقة A4 */
    public const MAX = 300;

    /**
     * مقاسات الطباعة (docs/plan/63): ملصقات الطابعات الحرارية الشائعة — ومنها مقاسا الوصل المطبوع
     * مسبقاً — أو أربعةٌ في ورقة A4. [الاسم، العرض، الطول] بالمليمتر للملصق الواحد.
     */
    public const SIZES = [
        'a6' => ['ملصق ١٠٠×١٥٠ ملم', 100, 150],
        '80x120' => ['ملصق ١٢٠×٨٠ ملم', 80, 120],
        '100x100' => ['ملصق ١٠٠×١٠٠ ملم', 100, 100],
        // وصلٌ واحدٌ يملأ الورقة (docs/plan/94)
        'a5' => ['ورقة A5', 148, 210],
        'a4one' => ['ورقة A4', 210, 297],
        'a3' => ['ورقة A3', 297, 420],
        'a4' => ['A4 — أربعة في الورقة', 105, 148.5],
    ];

    /** ورقٌ بمقاسه المعروف في الطابعة: وصلٌ واحدٌ في الورقة */
    public const PAPER = ['a5' => 'A5', 'a4one' => 'A4', 'a3' => 'A3'];

    /**
     * «الطابعة» في تطبيق التاجر (docs/plan/87، 94): ملصقات الطابعة الحرارية — المربّع، والطويل بعرض ٨ سم وطول ١٢،
     * و١٠×١٥ — وورق A5 وA4 وA3 بوصلٍ واحدٍ يملأ الورقة.
     */
    public const APP_SIZES = ['100x100', '80x120', 'a6', 'a5', 'a4one', 'a3'];

    /** ما يُطبع للتاجر: شحناته المدخلة التي لم يستلمها مندوبٌ بعد */
    public const ENTERED = [ShipmentStatus::Created, ShipmentStatus::PendingPickup];

    public function staff(Request $request): View
    {
        return $this->render($request, Shipment::query()->visibleTo($request->user()));
    }

    public function portal(Request $request): View
    {
        $query = Shipment::query()->where('merchant_id', $request->user()->merchant_id);

        // «وصولات الشحنات الجديدة»: ما لم يُستلَم بعد، ليُلصَق قبل أن يأتي المندوب
        if ($request->query('new')) {
            $query->whereIn('status', array_map(fn (ShipmentStatus $s) => $s->value, self::ENTERED));
        }

        return $this->render($request, $query, all: (bool) $request->query('new'));
    }

    /**
     * من تطبيق التاجر (docs/plan/63): رابطٌ موقَّعٌ لساعة يحمل التاجر والشحنات والمقاس، يُفتح في متصفّح
     * الهاتف فيطبع — بلا دخول، ولا يُفتح بغير ما وُقِّع عليه.
     */
    public function app(Request $request, Merchant $merchant): View
    {
        return $this->render($request, Shipment::query()->where('merchant_id', $merchant->id), sizes: self::APP_SIZES);
    }

    /**
     * بادئة الشركة عند الوسيط (docs/plan/109): باركود الوصل يحملها قبل رقمه (ZAJ_000293)، فيقرأه نظامهم رقماً
     * لطلبنا عندهم كما أُنشئ (AggregatorLink::companyOrderId)، ونقرؤه نحن رقم وصلٍ (ScanCode). بلا ربطٍ مفعّل: الرقم وحده.
     */
    public static function aggregatorPrefix(): ?string
    {
        if (! FeatureGate::enabled(Feature::Aggregator)) {
            return null;
        }

        $prefix = trim((string) AggregatorLink::query()->value('order_prefix'));

        return $prefix === '' ? null : $prefix;
    }

    /**
     * ما يُطبع باركوداً:
     * - في شركةٍ مربوطةٍ بوسيطٍ ببادئة: رقم الوصل ببادئته، كما أُنشئ الطلب عندهم — ولو كان للشحنة وصلٌ مطبوعٌ
     *   مسبقاً. فورقة الدفتر تحمل رمزها هي (90001234)، ونظامهم لا يعرفه: يُطبع لها هذا الوصل فيقرؤه مندوبهم
     *   (docs/plan/121). ورمز الدفتر يُكتب في الوصل، وماسحاتنا تقرأ الاثنين.
     * - وبلا ربط: الوصل المطبوع مسبقاً برمزه كما هو، وغيره رقمه.
     */
    public static function labelCode(Shipment $shipment, ?string $prefix): string
    {
        if (filled($prefix)) {
            return $prefix.$shipment->number;
        }

        return self::preprinted($shipment) ? (string) $shipment->barcode : $shipment->number;
    }

    /** للشحنة وصلٌ مطبوعٌ مسبقاً (دفتر الوصولات أو نظام المتجر) برمزٍ غير رقمها */
    public static function preprinted(Shipment $shipment): bool
    {
        return filled($shipment->barcode) && $shipment->barcode !== $shipment->number;
    }

    /**
     * «الطابعة» (docs/plan/79): ما فُتح للطباعة صار «مطبوعاً» — من التطبيق أو بوابة التاجر أو الموظّف.
     * أوّل طباعة وحدها تُسجَّل، وإعادة الطباعة لا تغيّرها. ولا يمسّ updated_at: الطباعة ليست تعديلاً.
     *
     * @param  list<int>  $ids
     */
    public static function markPrinted(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            Shipment::query()->whereIn('id', $chunk)->whereNull('printed_at')->toBase()->update(['printed_at' => now()]);
        }
    }

    /**
     * ما طُبع باركوداً في وصل كلّ شحنة (docs/plan/121)، آخر طباعةٍ لا أوّلها: الطرد يحمل آخر وصلٍ لُصق عليه. ولا يمسّ
     * updated_at.
     *
     * @param  Collection<int, Shipment>  $shipments
     */
    public static function recordCodes(Collection $shipments, ?string $prefix): void
    {
        foreach ($shipments as $shipment) {
            $code = self::labelCode($shipment, $prefix);

            if ($shipment->label_code !== $code) {
                Shipment::query()->whereKey($shipment->id)->toBase()->update(['label_code' => $code]);
            }
        }
    }

    /** @param  list<string>|null  $sizes  المقاسات المعروضة، وبلا قيدٍ كلّها */
    private function render(Request $request, Builder $query, bool $all = false, ?array $sizes = null): View
    {
        $sizes = $sizes === null ? self::SIZES : array_intersect_key(self::SIZES, array_flip($sizes));
        $ids = collect((array) $request->query('ids'))->map(fn ($id) => (int) $id)->filter()->unique();

        abort_if(! $all && $ids->isEmpty(), 404, 'لم تُختَر شحنات للطباعة.');

        $shipments = $query
            ->when(! $all, fn ($q) => $q->whereIn('id', $ids->take(self::MAX)))
            ->with(['merchant:id,business_name,phone', 'governorate:id,name_ar', 'city:id,name_ar'])
            ->orderBy('id')
            ->limit(self::MAX)
            ->get();

        abort_if($shipments->isEmpty(), 404, 'لا شحنات للطباعة.');

        self::markPrinted($shipments->pluck('id')->all());
        $prefix = self::aggregatorPrefix();
        self::recordCodes($shipments, $prefix);

        return view('labels.print', [
            'shipments' => $shipments,
            'prefix' => $prefix,
            'size' => array_key_exists((string) $request->query('size'), $sizes) ? (string) $request->query('size') : array_key_first($sizes),
            'sizes' => $sizes,
        ]);
    }
}
