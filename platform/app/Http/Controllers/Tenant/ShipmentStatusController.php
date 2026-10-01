<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\ChangeStatusInBulk;
use App\Actions\Shipments\SendOutForDelivery;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkStatusRequest;
use App\Http\Requests\ChangeStatusRequest;
use App\Models\Courier;
use App\Models\Shipment;
use App\Services\Shipments\ShipmentFilters;
use App\Support\Arabic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

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
     * «الكل» يُعاد بحثه هنا بالفلاتر نفسها، ويُقارَن عدده بما رآه الموظّف:
     * شحنةٌ دخلت القائمة بعد فتحها (أُسندت للمندوب نفسه مثلاً) لا تُعلَن
     * «واصل» وهو لم يرَها.
     */
    public function bulk(BulkStatusRequest $request, ChangeStatusInBulk $bulk): RedirectResponse
    {
        $user = $request->user();
        $to = ShipmentStatus::from($request->validated('status'));

        $query = Shipment::query()->visibleTo($user)->with('deliveryCourier:id,name')->orderBy('shipments.id');

        if ($request->boolean('all')) {
            $filters = Arr::only((array) $request->validated('filters', []), ShipmentFilters::KEYS);
            ShipmentFilters::apply($query, Request::create('/', 'GET', $filters));

            $count = (clone $query)->count();
            $expected = (int) $request->validated('expected');

            if ($count !== $expected) {
                return back()->withErrors(['shipment_ids' => 'تغيّرت القائمة منذ فتحتها: كانت '.number_format($expected)
                    .' وصارت '.number_format($count).'. راجعها ثم أعد التحديث.']);
            }

            if ($count > ChangeStatusInBulk::MAX) {
                return back()->withErrors(['shipment_ids' => 'في القائمة '.number_format($count).' شحنة، والحدّ '
                    .ChangeStatusInBulk::MAX.' في المرّة — اختر يوماً أو مندوباً ثم أعد.']);
            }
        } else {
            $query->whereIn('shipments.id', $request->validated('shipment_ids'));
        }

        $courier = null;

        if ($to === ShipmentStatus::OutForDelivery) {
            $courier = $this->courier($request, (int) $request->validated('courier_id'));

            if (! $courier) {
                return back()->withErrors(['courier_id' => 'المندوب غير موجود أو غير مفعّل أو ليس مندوب توصيل.']);
            }
        }

        // ألف شحنة تمرّ كلٌّ منها بمدخلها وقيودها: مهلةٌ تكفيها، لا مهلة الطلب العاديّ
        set_time_limit(180);

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

    /**
     * رسالة الدفعة: كم تحرّك، وما استُلم أوّلاً، وما تُخطّي ولماذا — شحنةً شحنة.
     * ولا شيء تحرّك: رسالةٌ حمراء لا خضراء.
     *
     * @param  array{moved: list<string>, received: list<string>, skipped: array<string, string>}  $result
     * @param  string  $done  جملة النجاح، و:count مكان العدد
     */
    protected function outcome(array $result, string $done, string $none): RedirectResponse
    {
        ['moved' => $moved, 'received' => $received, 'skipped' => $skipped] = $result;

        $list = fn (array $numbers) => implode('، ', array_slice($numbers, 0, 5)).(count($numbers) > 5 ? '…' : '');

        $message = $moved
            ? str_replace(':count', Arabic::shipments(count($moved)), $done)
                .($received ? ' (استُلمت من التاجر أوّلاً: '.$list($received).')' : '').'.'
            : $none;

        if ($skipped) {
            $message .= ' تُخطّيت '.Arabic::shipments(count($skipped)).': '
                .$list(array_map(fn ($number, $reason) => "{$number} ({$reason})", array_keys($skipped), $skipped)).'.';
        }

        return $moved
            ? back()->with('success', $message)
            : back()->withErrors(['shipment_ids' => $message]);
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
