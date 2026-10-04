<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ShipmentTickets;
use App\Http\Controllers\Controller;
use App\Models\Governorate;
use App\Models\ShipmentTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «طلبات المناديب لتغيير المبلغ» — الكول سنتر (docs/plan/30).
 *
 * المندوب عند الباب والزبون ينتظر: الأقدم أوّلاً. وكل موظّفةٍ ترى طلبات
 * محافظات اختصاصها وحدها، ومن لا محافظات له يرى كلّها.
 */
class ShipmentTicketController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $tab = $request->query('tab') === 'done' ? 'done' : 'open';
        $base = fn () => ShipmentTicket::query()->visibleTo($user);

        $tickets = $base()
            ->when($tab === 'open',
                fn ($q) => $q->open()->oldest('shipment_tickets.id'),
                // ما حُسم في الأيام السبعة الأخيرة: اعتمادٌ ورفضٌ وما أُغلق وحده
                fn ($q) => $q->where('shipment_tickets.status', '!=', 'open')
                    ->where('shipment_tickets.updated_at', '>=', now()->subDays(7))
                    ->latest('shipment_tickets.updated_at'))
            ->with(['shipment:id,number,recipient_name,recipient_phone,merchant_id,city_id,cod_amount,status',
                'shipment.merchant:id,business_name,phone', 'shipment.city:id,name_ar',
                'governorate:id,name_ar', 'courier:id,name,phone', 'handledBy:id,name'])
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        $mine = $user->handledGovernorateIds();

        return view('tenant.tickets.index', [
            'tab'          => $tab,
            'tickets'      => $tickets,
            'openCount'    => $base()->open()->count(),
            'governorates' => $mine === [] ? collect()
                : Governorate::query()->whereIn('id', $mine)->orderedForCompany()->pluck('name_ar'),
        ]);
    }

    public function approve(Request $request, ShipmentTicket $ticket, ShipmentTickets $tickets): RedirectResponse
    {
        $data = $request->validate([
            'approved_amount' => ['required', 'integer', 'min:0', 'max:'.ShipmentTickets::MAX_AMOUNT],
            'reply'           => ['nullable', 'string', 'max:255'],
        ], [], ['approved_amount' => 'المبلغ المعتمد', 'reply' => 'الردّ للمندوب']);

        $tickets->approve($ticket, (int) $data['approved_amount'], $data['reply'] ?? null, $request->user());

        return back()->with('success', "اعتُمد الطلب {$ticket->number} بـ".number_format((int) $data['approved_amount'])
            .' د.ع — يراه المندوب الآن في صفحة الشحنة.');
    }

    public function reject(Request $request, ShipmentTicket $ticket, ShipmentTickets $tickets): RedirectResponse
    {
        $data = $request->validate([
            'reply' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reply.required' => 'اكتب للمندوب سبب الرفض — يقرؤه عند الباب.',
        ], ['reply' => 'الردّ للمندوب']);

        $tickets->reject($ticket, $data['reply'], $request->user());

        return back()->with('success', "رُفض الطلب {$ticket->number} — يسلّم المندوب بالمبلغ الأصلي أو يسجّلها لم تُسلَّم.");
    }
}
