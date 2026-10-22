<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Support\Claims;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «أعالجها» و«أفلتها» (docs/plan/73): من فتحت تذكرةً أو شحنة معالجةٍ أو محادثة تاجرٍ حجزتها، فلا
 * تعالجها زميلتها حتى تحسمها أو تفلتها أو تمضي ربع ساعةٍ بلا عمل.
 */
class ClaimController extends Controller
{
    public function take(Request $request, string $subject, int $id): RedirectResponse
    {
        $this->authorizeSubject($request, $subject, $id);
        Claims::take($subject, $id, $request->user());

        return redirect()->to(url()->previous().'#'.self::anchor($subject, $id))
            ->with('success', 'صارت لك — زميلاتك يرينها باسمك ولا يعالجنها.');
    }

    public function release(Request $request, string $subject, int $id): RedirectResponse
    {
        $this->authorizeSubject($request, $subject, $id);
        Claims::release($subject, $id, $request->user());

        return redirect()->to(url()->previous().'#'.self::anchor($subject, $id))
            ->with('success', 'أفلتّها — تعالجها أيّ زميلة.');
    }

    /** يرى الطلب ويملك أن يعالجه، كما في شاشته */
    private function authorizeSubject(Request $request, string $subject, int $id): void
    {
        $user = $request->user();

        $ok = match ($subject) {
            Claims::TICKET => $user->can('tickets.handle')
                && ShipmentTicket::query()->visibleTo($user)->whereKey($id)->open()->exists(),
            Claims::PROCESSING => $user->can('shipments.status')
                && Shipment::query()->visibleTo($user)->inGovernoratesOf($user)->whereKey($id)->exists(),
            Claims::CONVERSATION => $user->can('support.reply')
                && Conversation::query()->whereKey($id)->exists(),
            default => false,
        };

        abort_unless($ok, 404);
    }

    private static function anchor(string $subject, int $id): string
    {
        return match ($subject) {
            Claims::TICKET => 'ticket-'.$id,
            Claims::PROCESSING => 'row-'.$id,
            default => 'reply',
        };
    }
}
