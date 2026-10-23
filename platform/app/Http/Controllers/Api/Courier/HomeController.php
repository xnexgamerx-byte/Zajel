<?php

namespace App\Http\Controllers\Api\Courier;

use App\Actions\Returns\ReceiveReturns;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\CourierThread;
use App\Models\PickupRequest;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use App\Models\Transaction;
use App\Support\FeatureGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * رئيسية تطبيق المندوب وحسابه (docs/plan/66): الأرقام من الدفتر والحالات نفسها التي تقرأها
 * شاشته في الموقع والشركة، فلا رقمان لشيءٍ واحد.
 */
class HomeController extends Controller
{
    private const SETTLEMENT_STATUSES = [
        'draft' => 'مسوّدة',
        'confirmed' => 'مُقفَل',
        'paid' => 'مدفوع',
        'cancelled' => 'ملغى',
    ];

    public function home(Request $request): JsonResponse
    {
        $courier = $this->courier($request);
        $user = $request->user();

        $inHand = Shipment::query()->where('delivery_courier_id', $courier->id)
            ->where('status', ShipmentStatus::OutForDelivery->value);

        $today = Shipment::query()->where('delivery_courier_id', $courier->id)
            ->whereOnDate('status_changed_at', today())
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $returns = Shipment::query()->where('delivery_courier_id', $courier->id)
            ->where(fn ($q) => ReceiveReturns::withCourier($q));

        $hour = (int) now('Asia/Baghdad')->format('G');

        return response()->json([
            'greeting' => $hour < 12 ? 'صباح الخير' : 'مساء الخير',
            'name' => $courier->name,
            'type' => $courier->type,
            'unread' => Announcement::for($user)->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))->count(),
            // ما يعمله: توصيل أو استلام أو كلاهما — يُظهر التطبيق شاشاته وحدها (docs/plan/71)
            'delivers' => $courier->delivers(),
            'picks' => $courier->picks(),
            'pickups' => $courier->picks()
                ? PickupRequest::where('courier_id', $courier->id)->whereIn('status', ['assigned', 'in_progress'])->count()
                : 0,
            // المحادثة مع المكتب إن فُتحت للشركة، ونقطةٌ إن كتب المكتب ولم يُقرأ
            'chat' => FeatureGate::enabled(Feature::Conversations)
                ? ['unread' => CourierThread::where('courier_id', $courier->id)->where('courier_unread', true)->exists()]
                : null,
            'in_hand' => [
                'count' => (clone $inHand)->count(),
                'to_collect' => (int) (clone $inHand)->sum('cod_amount'),
            ],
            'cash' => self::cashSummary($courier),
            'today' => [
                'delivered' => (int) (($today[ShipmentStatus::Delivered->value] ?? 0) + ($today[ShipmentStatus::PartiallyDelivered->value] ?? 0)),
                'failed' => (int) ($today[ShipmentStatus::FailedAttempt->value] ?? 0),
                'postponed' => (int) ($today[ShipmentStatus::Postponed->value] ?? 0),
                'collected' => (int) Shipment::query()->where('delivery_courier_id', $courier->id)
                    ->whereOnDate('delivered_at', today())->sum('collected_amount'),
            ],
            'returns' => (clone $returns)->count(),
            'attention' => $this->attention($courier, $returns),
            // مواعيد المؤجّلة بيده (docs/plan/75): تُذكَّر بها الرئيسية، ويُنبّهه الهاتف في موعدها
            'appointments' => Shipment::query()->where('delivery_courier_id', $courier->id)
                ->where('status', ShipmentStatus::Postponed->value)->whereNotNull('scheduled_at')
                ->with('city:id,name_ar')->orderBy('scheduled_at')->limit(100)
                ->get(['id', 'number', 'recipient_name', 'recipient_phone', 'city_id', 'scheduled_at', 'cod_amount'])
                ->map(fn (Shipment $s) => [
                    'id' => $s->id,
                    'number' => $s->number,
                    'recipient' => $s->recipient_name,
                    'phone' => $s->recipient_phone,
                    'city' => $s->city?->name_ar,
                    'amount' => (int) $s->cod_amount,
                    'at' => $s->scheduled_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    /** «شكد بيدي؟» — النقد والعمولة والحركات من دفتر الحركات نفسه */
    public function cash(Request $request): JsonResponse
    {
        $courier = $this->courier($request);

        return response()->json([
            ...self::cashSummary($courier),
            'movements' => Transaction::forAccount('courier', $courier->id)
                ->with('shipment:id,number')->latest('id')->limit(40)->get()
                ->map(fn (Transaction $t) => [
                    'id' => $t->id,
                    'category' => $t->category,
                    'label' => $t->categoryLabel(),
                    'direction' => $t->direction,
                    'amount' => (int) $t->amount,
                    'shipment' => $t->shipment?->number,
                    'description' => $t->description,
                    'at' => $t->created_at?->toIso8601String(),
                ])->values(),
            'settlements' => CourierSettlement::where('courier_id', $courier->id)->latest('id')->limit(5)->get()
                ->map(fn (CourierSettlement $s) => [
                    'code' => $s->code,
                    'status' => $s->status,
                    'status_label' => self::SETTLEMENT_STATUSES[$s->status] ?? $s->status,
                    'shipments' => (int) $s->shipments_count,
                    'net' => (int) $s->net_amount,
                    'date' => $s->created_at?->toDateString(),
                ])->values(),
        ]);
    }

    /** النقد بيده وسقفه وعمولته والواجب تسليمه — ما تحسبه الشركة نفسه (Courier::netDue) */
    public static function cashSummary(Courier $courier): array
    {
        $limit = (int) $courier->cash_limit;

        return [
            'in_hand' => (int) $courier->cash_in_hand,
            'limit' => $limit,
            'percent' => $limit > 0 ? min(100, intdiv((int) $courier->cash_in_hand * 100, $limit)) : null,
            'limit_reached' => $courier->hasReachedCashLimit(),
            'commission' => (int) $courier->commission_balance,
            'net_due' => $courier->netDue(),
        ];
    }

    /**
     * «تحتاج انتباهك»: طلب تغيير مبلغٍ ينتظر أو اعتُمد، وشحنةٌ تُسلَّم بكود، وراجعٌ بيده.
     *
     * @return list<array{kind: string, id: int, number: string, text: string}>
     */
    private function attention(Courier $courier, $returns): array
    {
        $items = [];

        $tickets = ShipmentTicket::query()->where('courier_id', $courier->id)
            ->where(fn ($q) => $q->open()->orWhere(fn ($w) => $w->awaitingPartial()))
            ->whereHas('shipment', fn ($q) => $q->where('status', ShipmentStatus::OutForDelivery->value)
                ->where('delivery_courier_id', $courier->id))
            ->with('shipment:id,number')->latest('id')->limit(5)->get();

        foreach ($tickets as $ticket) {
            $items[] = [
                'kind' => 'ticket',
                'id' => $ticket->shipment_id,
                'number' => $ticket->shipment->number,
                'text' => $ticket->status === 'open'
                    ? 'طلب تغيير المبلغ ينتظر جواب الكول سنتر'
                    : 'اعتُمد واصلٌ جزئي بمبلغ '.number_format((int) $ticket->approved_amount),
            ];
        }

        $coded = Shipment::query()->where('delivery_courier_id', $courier->id)
            ->where('status', ShipmentStatus::OutForDelivery->value)
            ->whereNotNull('delivery_code')->where('delivery_code', '!=', '')
            ->latest('assigned_at')->limit(5)->get(['id', 'number']);

        foreach ($coded as $shipment) {
            $items[] = ['kind' => 'code', 'id' => $shipment->id, 'number' => $shipment->number, 'text' => 'يُسلَّم بكود من الزبون'];
        }

        foreach ((clone $returns)->orderBy('status_changed_at')->limit(5)->get(['id', 'number']) as $shipment) {
            $items[] = ['kind' => 'return', 'id' => $shipment->id, 'number' => $shipment->number, 'text' => 'راجعٌ بيدك: سلّمه للمخزن'];
        }

        return $items;
    }

    private function courier(Request $request): Courier
    {
        return $request->attributes->get('courier');
    }
}
