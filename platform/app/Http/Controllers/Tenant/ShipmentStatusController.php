<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeStatusRequest;
use App\Models\Courier;
use App\Models\Shipment;
use App\Support\Arabic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShipmentStatusController extends Controller
{
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
            'forced_reason'     => $request->boolean('force') ? $request->validated('forced_reason') : null,
        ], fn ($v) => $v !== null));

        return back()->with('success', $request->boolean('force')
            ? "تم تحديث الشحنة {$shipment->number} إلى «{$to->label()}» إجبارياً، وسُجّل السبب باسمك."
            : "تم تحديث الشحنة {$shipment->number} إلى «{$to->label()}».");
    }

    /**
     * إسناد مجموعة شحنات إلى مندوب دفعة واحدة.
     *
     * هذه العملية اليومية الأكثر تكراراً في شركة توصيل: صباحاً يوزَّع
     * ما في المخزن على المندوبين. شحنة شحنة يعني ساعة عمل كل يوم.
     *
     * وما لم يُستلم بعد («تم الإنشاء» أو «بانتظار الاستلام») يُسجَّل استلامه
     * ثم يخرج — من يُسندها بيده الطرد، كما في الإدخال السريع ومسح «مندوب
     * توصيل للكل» في المعتاد. وما عدا ذلك يُتخطّى ويُقال لماذا، شحنةً شحنة.
     */
    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shipment_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'shipment_ids.*' => ['integer'],
            'courier_id'     => ['required', 'integer'],
        ], [], ['shipment_ids' => 'الشحنات', 'courier_id' => 'المندوب']);

        $courier = Courier::delivering()->active()->visibleTo($request->user())->find($data['courier_id']);

        if (! $courier) {
            return back()->withErrors(['courier_id' => 'المندوب غير موجود أو غير مفعّل أو ليس مندوب توصيل.']);
        }

        $shipments = Shipment::whereIn('id', $data['shipment_ids'])
            ->visibleTo($request->user())
            ->with('deliveryCourier:id,name')
            ->orderBy('id')
            ->get();

        $moved = [];
        $received = [];
        $skipped = [];

        foreach ($shipments as $shipment) {
            if ($reason = $this->cannotGoOut($shipment, $courier)) {
                $skipped[$shipment->number] = $reason;

                continue;
            }

            try {
                // الخطوتان معاً أو لا شيء: لا تبقى «مستلَمة» بلا مندوب إن سقطت الثانية
                $wasReceived = DB::transaction(function () use ($shipment, $courier, $request) {
                    $notYet = in_array($shipment->status, [ShipmentStatus::Created, ShipmentStatus::PendingPickup], true);

                    if ($notYet) {
                        $this->changeStatus->handle($shipment, ShipmentStatus::PickedUp, $request->user(), [
                            'note' => 'استُلمت عند الإسناد إلى '.$courier->name,
                        ]);
                    }

                    $this->changeStatus->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $request->user(), [
                        'courier_id' => $courier->id,
                        'note'       => 'إسناد جماعي',
                    ]);

                    return $notYet;
                });
            } catch (ValidationException $e) {
                // سبقه أحدٌ إليها بين الاختيار والحفظ: تُتخطّى بسببها ولا يسقط الباقي
                $skipped[$shipment->number] = collect($e->errors())->flatten()->first();

                continue;
            }

            $moved[] = $shipment->number;

            if ($wasReceived) {
                $received[] = $shipment->number;
            }
        }

        $list = fn (array $numbers) => implode('، ', array_slice($numbers, 0, 5)).(count($numbers) > 5 ? '…' : '');

        $message = $moved
            ? 'أُسندت '.Arabic::shipments(count($moved))." إلى {$courier->name}"
                .($received ? ' (استُلمت من التاجر أوّلاً: '.$list($received).')' : '').'.'
            : "لم تُسنَد أيّ شحنة إلى {$courier->name}.";

        if ($skipped) {
            $message .= ' تُخطّيت '.Arabic::shipments(count($skipped)).': '
                .$list(array_map(fn ($number, $reason) => "{$number} ({$reason})", array_keys($skipped), $skipped)).'.';
        }

        // لا شيء خرج: رسالةٌ حمراء لا خضراء
        return $moved
            ? back()->with('success', $message)
            : back()->withErrors(['shipment_ids' => $message]);
    }

    /** لماذا لا تخرج هذه الشحنة مع هذا المندوب — أو null إن كانت تخرج */
    protected function cannotGoOut(Shipment $shipment, Courier $courier): ?string
    {
        $status = $shipment->status;

        return match (true) {
            // والمعلَّقة للمراجعة لا تخرج حتى تُجاز
            $shipment->isHeldForReview()                  => 'تحت المراجعة',
            $status === ShipmentStatus::OutForDelivery    => (int) $shipment->delivery_courier_id === (int) $courier->id
                ? 'معه سلفاً'
                : 'مع '.($shipment->deliveryCourier?->name ?? 'مندوبٍ آخر'),
            in_array($status, [ShipmentStatus::Created, ShipmentStatus::PendingPickup], true) => null,
            ! $status->canMoveTo(ShipmentStatus::OutForDelivery) => $status->label(),
            default                                        => null,
        };
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
