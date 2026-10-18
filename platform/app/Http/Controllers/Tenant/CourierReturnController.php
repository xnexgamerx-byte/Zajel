<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «كشوفات رواجع المندوبين»: مرجع كلّ راجعٍ رجع على يد مندوب — ما بيده بعد، وما استُلم منه
 * بالمخزن (متى ومن استلمه)، وما سُلّم لتاجره (متى وبأيّ إيصال)، وهل حوسب المندوب عليه.
 *
 * لا يغيّر شيئاً: يقرأ ما سجّلته «استلام الراجع من المندوب» و«تسليم الراجع للتاجر» وكشوف المناديب.
 */
class CourierReturnController extends Controller
{
    /** مراحل الراجع من المندوب إلى التاجر */
    public const STAGES = [
        'with_courier' => 'بيد المندوب',
        'at_hub'       => 'استُلم بالمخزن',
        'to_merchant'  => 'سُلّم للتاجر',
    ];

    public function index(Request $request): View
    {
        // ملخّص كلّ مندوب للفترة والمرحلة المختارتين — والجدول تحته شحنةً شحنة
        $summary = $this->returns($request, withCourier: false)
            ->toBase()
            ->selectRaw('shipments.delivery_courier_id as courier_id, count(*) as total')
            ->selectRaw('sum(case when '.$this->stageSql('with_courier').' then 1 else 0 end) as with_courier')
            ->selectRaw('sum(case when '.$this->stageSql('at_hub').' then 1 else 0 end) as at_hub')
            ->selectRaw('sum(case when '.$this->stageSql('to_merchant').' then 1 else 0 end) as to_merchant')
            ->selectRaw('sum(case when '.$this->unsettledSql().' then 1 else 0 end) as unsettled')
            ->groupBy('shipments.delivery_courier_id')
            ->get();

        $names = Courier::whereIn('id', $summary->pluck('courier_id'))->pluck('name', 'id');

        return view('tenant.returns.couriers', [
            'summary'  => $summary->map(fn ($row) => (object) [
                'courier_id'   => (int) $row->courier_id,
                'name'         => $names[$row->courier_id] ?? '—',
                'total'        => (int) $row->total,
                'with_courier' => (int) $row->with_courier,
                'at_hub'       => (int) $row->at_hub,
                'to_merchant'  => (int) $row->to_merchant,
                'unsettled'    => (int) $row->unsettled,
            ])->sortByDesc('total')->values(),
            'returns'  => $this->returns($request)
                ->with([
                    'merchant:id,business_name', 'deliveryCourier:id,name,commission_per_return',
                    'lastFailureReason:id,name_ar', 'returnBatch:id,number',
                    'courierSettlement:id,code,status', 'returnReceivedBy:id,name',
                ])
                ->orderByDesc('shipments.status_changed_at')
                ->orderByDesc('shipments.id')
                ->paginate(50)
                ->withQueryString(),
            'couriers' => Courier::delivering()->visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
            'stages'   => self::STAGES,
        ]);
    }

    /** مرحلة الراجع الآن: بيد المندوب، أو بالمخزن، أو عند تاجره */
    public static function stageOf(Shipment $shipment): string
    {
        return match (true) {
            $shipment->status === ShipmentStatus::Returned => 'to_merchant',
            $shipment->status === ShipmentStatus::Returning && $shipment->return_received_at !== null => 'at_hub',
            default => 'with_courier',
        };
    }

    /**
     * المحاسبة مع المندوب على الراجع: حوسب في كشفه، أو له عمولةٌ تنتظر الكشف، أو لا شيء عليه.
     *
     * @return array{0: string, 1: string} النصّ ولون الشارة
     */
    public static function settlementOf(Shipment $shipment): array
    {
        if ($shipment->courier_settlement_id) {
            return ['حوسب · '.($shipment->courierSettlement?->code ?? ''), 'chip-ok'];
        }

        if ($shipment->wasDelivered() || (int) $shipment->courier_commission > 0) {
            return ['لم يُحاسَب بعد', 'chip-warn'];
        }

        // عمولة الراجع تُقيَّد حين يُسلَّم لتاجره (ChangeShipmentStatus)
        if ($shipment->status !== ShipmentStatus::Returned && (int) $shipment->deliveryCourier?->commission_per_return > 0) {
            return ['عمولته عند تسليمه للتاجر', 'chip-mute'];
        }

        return ['لا شيء عليه', 'chip-mute'];
    }

    /**
     * الرواجع التي مرّت بمندوب توصيل ويراها الموظّف: بيده، أو بالمخزن، أو سُلّمت لتاجرها —
     * ومعها باقي الواصل الجزئي وقديم الاستبدال.
     *
     * @return Builder<Shipment>
     */
    private function returns(Request $request, bool $withCourier = true): Builder
    {
        $stage = $request->query('stage');
        $term = trim((string) $request->query('q'));

        return Shipment::query()
            ->visibleTo($request->user())
            ->whereNotNull('shipments.delivery_courier_id')
            ->whereIn('shipments.status', [
                ShipmentStatus::Returning->value,
                ShipmentStatus::Returned->value,
                ShipmentStatus::PartiallyDelivered->value,
            ])
            ->when($withCourier && $request->integer('courier_id'), fn ($q, $id) => $q->where('shipments.delivery_courier_id', $id))
            ->when(isset(self::STAGES[$stage]), fn ($q) => $q->whereRaw($this->stageSql($stage)))
            ->when($request->query('settled') === 'yes', fn ($q) => $q->whereNotNull('shipments.courier_settlement_id'))
            ->when($request->query('settled') === 'no', fn ($q) => $q->whereRaw($this->unsettledSql()))
            ->when($request->date('from'), fn ($q, $d) => $q->whereFromDate('shipments.status_changed_at', $d->toDateString()))
            ->when($request->date('to'), fn ($q, $d) => $q->whereUntilDate('shipments.status_changed_at', $d->toDateString()))
            ->when($term !== '', fn ($q) => $q->search(mb_substr($term, 0, 60)));
    }

    /** شرط المرحلة بلغة SQL — للتصفية وللعدّ في الملخّص بالشرط نفسه */
    private function stageSql(string $stage): string
    {
        $returning = ShipmentStatus::Returning->value;

        return match ($stage) {
            'to_merchant' => "shipments.status = '".ShipmentStatus::Returned->value."'",
            'at_hub'      => "(shipments.status = '{$returning}' and shipments.return_received_at is not null)",
            default       => "((shipments.status = '{$returning}' and shipments.return_received_at is null) or shipments.status = '"
                .ShipmentStatus::PartiallyDelivered->value."')",
        };
    }

    /** ما على المندوب فيه حسابٌ لم يدخل كشفاً مُقفَلاً بعد: نقد واصلٍ جزئي، أو عمولة راجع */
    private function unsettledSql(): string
    {
        return '(shipments.courier_settlement_id is null and (shipments.delivered_at is not null or shipments.courier_commission > 0))';
    }
}
