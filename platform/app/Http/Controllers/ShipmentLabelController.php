<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
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
    private const MAX = 300;

    public function staff(Request $request): View
    {
        return $this->render($request, Shipment::query()->visibleTo($request->user()));
    }

    public function portal(Request $request): View
    {
        $query = Shipment::query()->where('merchant_id', $request->user()->merchant_id);

        // «وصولات الشحنات الجديدة»: ما لم يُستلَم بعد، ليُلصَق قبل أن يأتي المندوب
        if ($request->query('new')) {
            $query->whereIn('status', [ShipmentStatus::Created->value, ShipmentStatus::PendingPickup->value]);
        }

        return $this->render($request, $query, all: (bool) $request->query('new'));
    }

    private function render(Request $request, Builder $query, bool $all = false): View
    {
        $ids = collect((array) $request->query('ids'))->map(fn ($id) => (int) $id)->filter()->unique();

        abort_if(! $all && $ids->isEmpty(), 404, 'لم تُختَر شحنات للطباعة.');

        $shipments = $query
            ->when(! $all, fn ($q) => $q->whereIn('id', $ids->take(self::MAX)))
            ->with(['merchant:id,business_name,phone', 'governorate:id,name_ar', 'city:id,name_ar'])
            ->orderBy('id')
            ->limit(self::MAX)
            ->get();

        abort_if($shipments->isEmpty(), 404, 'لا شحنات للطباعة.');

        return view('labels.print', [
            'shipments' => $shipments,
            'size'      => $request->query('size') === 'a4' ? 'a4' : 'a6',
        ]);
    }
}
