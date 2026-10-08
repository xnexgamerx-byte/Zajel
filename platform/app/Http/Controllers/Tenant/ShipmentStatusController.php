<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\ChangeStatusInBulk;
use App\Actions\Shipments\SendOutForDelivery;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Concerns\ReportsBulkOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkStatusRequest;
use App\Http\Requests\ChangeStatusRequest;
use App\Models\Courier;
use App\Models\Shipment;
use App\Services\Shipments\BulkSelection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShipmentStatusController extends Controller
{
    use ReportsBulkOutcome;

    public function __construct(protected ChangeShipmentStatus $changeStatus) {}

    /** تغيير حالة شحنة واحدة من صفحة تفاصيلها. */
    public function update(ChangeStatusRequest $request, Shipment $shipment): RedirectResponse
    {
        $to = ShipmentStatus::from($request->validated('status'));

        $this->changeStatus->handle($shipment, $to, $request->user(), array_filter([
            'courier_id'        => $request->validated('courier_id'),
            'hub_id'            => $request->validated('hub_id'),
            'failure_reason_id' => $request->validated('failure_reason_id'),
            'note'              => $request->validated('note'),
            'collected_amount'  => $request->validated('collected_amount'),
            'force'             => $request->boolean('force') ?: null,
            'retry'             => $request->boolean('retry') ?: null,
            'forced_reason'     => $request->boolean('force') ? $request->validated('forced_reason') : null,
        ], fn ($v) => $v !== null));

        return back()->with('success', $request->boolean('force')
            ? "تم تحديث الشحنة {$shipment->number} إلى «{$to->label()}» إجبارياً، وسُجّل السبب باسمك."
            : "تم تحديث الشحنة {$shipment->number} إلى «{$to->label()}».");
    }

    /**
     * إسناد مجموعة شحنات إلى مندوب دفعة واحدة (SendOutForDelivery) — وهو
     * «قيد التوصيل — مع مندوب» في تحديث الحالة من القائمة.
     */
    public function assign(Request $request, SendOutForDelivery $sendOut): RedirectResponse
    {
        $data = $request->validate([
            'shipment_ids'   => ['required', 'array', 'min:1', 'max:'.ChangeStatusInBulk::MAX],
            'shipment_ids.*' => ['integer'],
            'courier_id'     => ['required', 'integer'],
        ], [], ['shipment_ids' => 'الشحنات', 'courier_id' => 'المندوب']);

        $courier = $this->courier($request, $data['courier_id']);

        if (! $courier) {
            return back()->withErrors(['courier_id' => 'المندوب غير موجود أو غير مفعّل أو ليس مندوب توصيل.']);
        }

        $shipments = Shipment::whereIn('id', $data['shipment_ids'])
            ->visibleTo($request->user())
            ->with('deliveryCourier:id,name')
            ->orderBy('id')
            ->get();

        return $this->outcome(
            $sendOut->handle($shipments, $courier, $request->user()),
            'أُسندت :count إلى '.$courier->name,
            "لم تُسنَد أيّ شحنة إلى {$courier->name}.",
        );
    }

    /**
     * تحديث الحالة من القائمة بلا دخول كل شحنة: المختارة بأرقامها، أو «الكل»
     * — كل ما يطابق بحث القائمة (يومٌ، مندوب، مرحلة) حتى ChangeStatusInBulk::MAX.
     *
     * «الكل» يُعاد بحثه بالفلاتر نفسها ويُقارَن عدده بما رآه الموظّف (BulkSelection):
     * شحنةٌ دخلت القائمة بعد فتحها (أُسندت للمندوب نفسه مثلاً) لا تُعلَن
     * «واصل» وهو لم يرَها.
     */
    public function bulk(BulkStatusRequest $request, ChangeStatusInBulk $bulk): RedirectResponse
    {
        $user = $request->user();
        $to = ShipmentStatus::from($request->validated('status'));

        $query = BulkSelection::resolve(
            Shipment::query()->visibleTo($user)->with('deliveryCourier:id,name')->orderBy('shipments.id'),
            $request->validated(),
        );

        if (is_string($query)) {
            return back()->withErrors(['shipment_ids' => $query]);
        }

        $courier = null;

        if ($to === ShipmentStatus::OutForDelivery) {
            $courier = $this->courier($request, (int) $request->validated('courier_id'));

            if (! $courier) {
                return back()->withErrors(['courier_id' => 'المندوب غير موجود أو غير مفعّل أو ليس مندوب توصيل.']);
            }
        }

        // ألف شحنة تمرّ كلٌّ منها بمدخلها وقيودها: مهلةٌ تكفيها، لا مهلة الطلب العاديّ
        // يرفع الحدّ ولا يخفضه: «بلا حدّ» (0) في سطر الأوامر والاختبارات يبقى كما هو
        if (($limit = (int) ini_get('max_execution_time')) > 0 && $limit < 180) {
            set_time_limit(180);
        }

        $result = $bulk->handle($query->get(), $to, $user, [
            'courier'           => $courier,
            'failure_reason_id' => $request->validated('failure_reason_id'),
            'note'              => $request->validated('note'),
        ]);

        $label = ChangeStatusInBulk::targets()[$to->value];

        return $to === ShipmentStatus::OutForDelivery
            ? $this->outcome($result, 'أُسندت :count إلى '.$courier->name, "لم تُسنَد أيّ شحنة إلى {$courier->name}.")
            : $this->outcome($result, 'حُدّثت :count إلى «'.$to->label().'»', "لم تُحدَّث أيّ شحنة إلى «{$label}».");
    }

    /** مندوب توصيلٍ مفعّل يراه الموظّف (من فرعه إن كان مقيَّداً بفرع) */
    protected function courier(Request $request, int $id): ?Courier
    {
        return Courier::delivering()->active()->visibleTo($request->user())->find($id);
    }

    /** ملخّص نقد المندوبين — من يحمل كم، ومن تجاوز سقفه. */
    public function cashBoard(Request $request)
    {
        $couriers = Courier::query()
            ->visibleTo($request->user())
            ->where(fn ($q) => $q->where('cash_in_hand', '!=', 0)->orWhere('commission_balance', '!=', 0))
            ->orderByDesc('cash_in_hand')
            ->get();

        // المجموع من الجدول لا من القائمة، والمحذوف الذي بيده نقدٌ فيه — ومن فرعه
        // وحده إن كان مقيَّداً بفرع
        $totals = Courier::withTrashed()
            ->visibleTo($request->user())
            ->toBase()
            ->selectRaw('sum(cash_in_hand) as cash, sum(commission_balance) as commission')
            ->first();

        return view('tenant.couriers.cash', [
            'couriers'   => $couriers,
            'total'      => (int) $totals->cash,
            'commission' => (int) $totals->commission,
        ]);
    }
}
