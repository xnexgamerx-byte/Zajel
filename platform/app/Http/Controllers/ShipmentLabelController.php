<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Models\Merchant;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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

        return view('labels.print', [
            'shipments' => $shipments,
            'size' => array_key_exists((string) $request->query('size'), $sizes) ? (string) $request->query('size') : array_key_first($sizes),
            'sizes' => $sizes,
        ]);
    }
}
