<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «الشحنات المؤرشفة»: ما رجع إلى تاجره وسُلّم له. انتهى عملها، فلا تُزاحم
 * الشحنات الجارية في قائمتها — وتُحفظ هنا، لكل تاجرٍ قائمته.
 *
 * الصفحة الأولى التجّار بعدد رواجعهم المسلَّمة وآخر تسليم، والتاجر المختار
 * تُفتح قائمته في المكان نفسه: رقم الوصل، والزبون، والوجهة، والمبلغ، وسبب
 * الراجع، وتاريخ تسليمه، وإيصال الدفعة التي سُلّم بها.
 *
 * والفرع يرى تجّاره وحدهم (Merchant::visibleTo)، وشحناتهم كما يراها في كل مكان.
 */
class ShipmentArchiveController extends Controller
{
    public const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $user = $request->user();
        $term = trim((string) $request->query('q'));

        if ($request->filled('merchant_id')) {
            return $this->merchant($request, Merchant::visibleTo($user)->findOrFail($request->integer('merchant_id')), $term);
        }

        $visibleMerchants = Merchant::visibleTo($user)
            ->when($term !== '', fn ($q) => $q->where('business_name', 'like', "%{$term}%"))
            ->select('id');

        $rows = Shipment::query()
            ->visibleTo($user)
            ->where('shipments.status', ShipmentStatus::Returned->value)
            ->whereIn('shipments.merchant_id', $visibleMerchants)
            ->selectRaw('shipments.merchant_id, count(*) as total, sum(shipments.cod_amount) as cod, max(shipments.returned_at) as last_returned_at')
            ->groupBy('shipments.merchant_id')
            ->orderByDesc('last_returned_at')
            ->toBase()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('tenant.shipments.archive', [
            'merchant'  => null,
            'rows'      => $rows,
            'merchants' => Merchant::whereIn('id', collect($rows->items())->pluck('merchant_id'))
                ->get(['id', 'business_name', 'phone', 'is_vip'])->keyBy('id'),
        ]);
    }

    private function merchant(Request $request, Merchant $merchant, string $term): View
    {
        $base = Shipment::query()
            ->visibleTo($request->user())
            ->where('shipments.merchant_id', $merchant->id)
            ->where('shipments.status', ShipmentStatus::Returned->value);

        $shipments = (clone $base)
            ->with(['governorate:id,name_ar', 'city:id,name_ar', 'lastFailureReason:id,name_ar', 'returnBatch:id,number'])
            ->search($term)
            ->when($this->date($request, 'from'), fn ($q, $d) => $q->whereFromDate('shipments.returned_at', $d))
            ->when($this->date($request, 'to'), fn ($q, $d) => $q->whereUntilDate('shipments.returned_at', $d))
            ->orderByDesc('shipments.returned_at')
            ->orderByDesc('shipments.id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.shipments.archive', [
            'merchant'  => $merchant,
            'shipments' => $shipments,
            'summary'   => (clone $base)->toBase()
                ->selectRaw('count(*) as total, sum(shipments.cod_amount) as cod, sum(shipments.return_fee) as fees')
                ->first(),
        ]);
    }

    private function date(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
