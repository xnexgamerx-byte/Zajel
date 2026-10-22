<?php

namespace App\Http\Controllers\Api\CallCenter;

use App\Actions\Shipments\ShipmentTickets;
use App\Actions\Support\Claims;
use App\Http\Controllers\Controller;
use App\Models\ShipmentTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «طلبات المناديب لتغيير المبلغ» في تطبيق الكول سنتر (docs/plan/30، 73): المندوب عند الباب والزبون
 * ينتظر — الأقدم أوّلاً، ومن فتحت الطلب تحسمه وحدها، بـShipmentTickets نفسه.
 */
class TicketController extends Controller
{
    use Concerns;

    private const WITH = ['shipment:id,number,recipient_name,recipient_phone,merchant_id,city_id,cod_amount,status',
        'shipment.merchant:id,business_name,phone', 'shipment.city:id,name_ar', 'governorate:id,name_ar', 'courier:id,name,phone'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tickets = ShipmentTicket::query()->visibleTo($user)->open()->oldest('shipment_tickets.id')
            ->with(self::WITH)->limit(100)->get();
        $claims = Claims::holders(Claims::TICKET, $tickets->pluck('id'));

        return response()->json([
            'data' => $tickets->map(fn (ShipmentTicket $t) => $this->row($t, $claims[$t->id] ?? null, $request))->values(),
        ]);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        $t = $this->find($request, $ticket);

        return response()->json(['data' => $this->row($t, Claims::holder(Claims::TICKET, $t->id), $request, full: true)]);
    }

    /** «أعالجها»: تصير لها، وزميلاتها يرين اسمها */
    public function claim(Request $request, int $ticket): JsonResponse
    {
        $t = $this->find($request, $ticket, open: true);
        Claims::take(Claims::TICKET, $t->id, $request->user());

        return $this->show($request, $t->id);
    }

    public function release(Request $request, int $ticket): JsonResponse
    {
        $t = $this->find($request, $ticket);
        Claims::release(Claims::TICKET, $t->id, $request->user());

        return response()->json(['message' => 'أفلتّها — تعالجها أيّ زميلة.']);
    }

    public function approve(Request $request, int $ticket, ShipmentTickets $tickets): JsonResponse
    {
        $t = $this->find($request, $ticket);
        $data = $request->validate([
            'approved_amount' => ['required', 'integer', 'min:0', 'max:'.ShipmentTickets::MAX_AMOUNT],
            'reply' => ['nullable', 'string', 'max:255'],
        ], [], ['approved_amount' => 'المبلغ المعتمد', 'reply' => 'الردّ للمندوب']);

        $tickets->approve($t, (int) $data['approved_amount'], $data['reply'] ?? null, $request->user());

        return response()->json(['message' => "اعتُمد الطلب {$t->number} بـ".number_format((int) $data['approved_amount'])
            .' د.ع — يراه المندوب الآن في صفحة الشحنة.']);
    }

    public function reject(Request $request, int $ticket, ShipmentTickets $tickets): JsonResponse
    {
        $t = $this->find($request, $ticket);
        $data = $request->validate([
            'reply' => ['required', 'string', 'min:3', 'max:255'],
        ], ['reply.required' => 'اكتب للمندوب سبب الرفض — يقرؤه عند الباب.'], ['reply' => 'الردّ للمندوب']);

        $tickets->reject($t, $data['reply'], $request->user());

        return response()->json(['message' => "رُفض الطلب {$t->number} — يسلّم المندوب بالمبلغ الأصلي أو يسجّلها لم تُسلَّم."]);
    }

    /** طلبٌ في محافظاتها — وغيره 404 كما في الموقع */
    private function find(Request $request, int $id, bool $open = false): ShipmentTicket
    {
        abort_unless($request->user()->can('tickets.handle'), 403);

        return ShipmentTicket::query()->visibleTo($request->user())->with(self::WITH)
            ->when($open, fn ($q) => $q->open())->findOrFail($id);
    }

    private function row(ShipmentTicket $t, $claim, Request $request, bool $full = false): array
    {
        $s = $t->shipment;

        return [
            'id' => $t->id,
            'number' => $t->number,
            'kind' => $t->kind,
            'kind_label' => $t->kindLabel(),
            'status' => $t->status,
            'status_label' => $t->statusLabel(),
            'current' => (int) $t->current_amount,
            'requested' => (int) $t->requested_amount,
            'reason' => $t->reason,
            'waiting_minutes' => self::minutesSince($t->created_at),
            'shipment' => [
                'id' => $s?->id,
                'number' => $s?->number,
                'recipient' => $s?->recipient_name,
                'phone' => $s?->recipient_phone,
                'place' => collect([$t->governorate?->name_ar, $s?->city?->name_ar])->filter()->implode(' · '),
            ],
            'courier' => ['name' => $t->courier?->name, 'phone' => $t->courier?->phone],
            'merchant' => ['name' => $s?->merchant?->business_name, 'phone' => $s?->merchant?->phone],
            'claim' => self::claimJson($claim, $request->user()),
            // ما يعنيه الاعتماد — في التفاصيل
            'effect' => $full ? ($t->kind === 'price'
                ? 'الاعتماد يغيّر مبلغ الشحنة الآن (ويُكتب في سجلّها)، ويسلّم المندوب «واصل» بالمبلغ الجديد.'
                : 'الاعتماد يفتح للمندوب «واصل جزئي» بهذا المبلغ، وباقي الطلب يرجع لتاجره راجعاً بلا أجرة راجع.') : null,
        ];
    }
}
