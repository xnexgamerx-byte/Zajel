<?php

namespace App\Http\Controllers\Api\CallCenter;

use App\Actions\Support\Claims;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\CourierThread;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShipmentTicket;
use App\Models\WorkClaim;
use App\Support\FeatureGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * رئيسية تطبيق الكول سنتر (docs/plan/73): ما ينتظرها الآن بترتيب الإلحاح — المندوب عند الباب
 * (تذاكر تغيير المبلغ)، ثم ما لم يُسلَّم (المعالجة)، ثم التجّار والمناديب الذين كتبوا — وما
 * تعالجه هي الآن، وما أنجزته اليوم. الأرقام من شاشاتها في الموقع نفسها.
 */
class HomeController extends Controller
{
    use Concerns;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $chat = FeatureGate::enabled(Feature::Conversations) && $user->can('support.reply');
        $hour = (int) now('Asia/Baghdad')->format('G');

        $tickets = $user->can('tickets.handle') ? ShipmentTicket::query()->visibleTo($user)->open() : null;
        $processing = $user->can('shipments.status')
            ? Shipment::query()->visibleTo($user)->inGovernoratesOf($user)->where('shipments.status', ShipmentStatus::FailedAttempt->value)
            : null;
        $conversations = $chat ? Conversation::query()->visibleTo($user)->where('status', 'open')->where('last_author', 'merchant') : null;

        $oldestTicket = $tickets ? (clone $tickets)->min('shipment_tickets.created_at') : null;
        $oldestCase = $processing ? (clone $processing)->min('shipments.status_changed_at') : null;
        $oldestChat = $conversations ? (clone $conversations)->min('last_message_at') : null;

        $mine = $user->handledGovernorateIds();

        return response()->json([
            'greeting' => $hour < 12 ? 'صباح الخير' : 'مساء الخير',
            'name' => $user->name,
            'governorates' => $mine === [] ? [] : Governorate::query()->whereIn('id', $mine)->orderedForCompany()->pluck('name_ar'),
            // الطوابير بترتيب الإلحاح، وكلٌّ بأقدم ما ينتظر فيها
            'queues' => [
                'tickets' => $tickets ? [
                    'count' => (clone $tickets)->count(),
                    'oldest_minutes' => self::minutesSince($oldestTicket ? Carbon::parse($oldestTicket) : null),
                    // المندوب عند الباب: بعد ربع ساعة يحمرّ
                    'late' => (clone $tickets)->where('shipment_tickets.created_at', '<=', now()->subMinutes(15))->count(),
                ] : null,
                'processing' => $processing ? [
                    'count' => (clone $processing)->count(),
                    'oldest_minutes' => self::minutesSince($oldestCase ? Carbon::parse($oldestCase) : null),
                    'late' => (clone $processing)->where('shipments.status_changed_at', '<=', now()->subDay())->count(),
                    'free' => (clone $processing)->whereNotIn('shipments.id', WorkClaim::query()->where('subject', Claims::PROCESSING)
                        ->where('seen_at', '>', now()->subMinutes(Claims::IDLE_MINUTES))->select('subject_id'))->count(),
                ] : null,
                'conversations' => $conversations ? [
                    'count' => (clone $conversations)->count(),
                    'oldest_minutes' => self::minutesSince($oldestChat ? Carbon::parse($oldestChat) : null),
                ] : null,
                'couriers' => $chat ? [
                    'count' => CourierThread::query()->where('staff_unread', true)->count(),
                ] : null,
            ],
            'mine' => Claims::mine($user)->map(fn (WorkClaim $c) => [
                'subject' => $c->subject,
                'id' => $c->subject_id,
                'title' => self::title($c),
                'since' => $c->claimed_at?->toIso8601String(),
            ])->values(),
            // ما أنجزته اليوم: تذاكر حسمتها، وشحنات عالجتها
            'today' => [
                'tickets' => ShipmentTicket::query()->where('handled_by_user_id', $user->id)
                    ->whereOnDate('handled_at', today())->count(),
                'processed' => ShipmentEvent::query()->where('event_type', 'processed')->where('actor_type', 'user')
                    ->where('actor_id', $user->id)->whereOnDate('created_at', today())->count(),
            ],
        ]);
    }

    /** «تذكرة TK… — الوصل 1802…»، «معالجة 1802…»، «محادثة: شحنتي تأخرت» */
    private static function title(WorkClaim $claim): string
    {
        return match ($claim->subject) {
            Claims::TICKET => 'تذكرة '.(ShipmentTicket::query()->whereKey($claim->subject_id)->value('number') ?? ''),
            Claims::PROCESSING => 'معالجة '.(Shipment::query()->whereKey($claim->subject_id)->value('number') ?? ''),
            default => 'محادثة: '.(Conversation::query()->whereKey($claim->subject_id)->value('subject') ?? ''),
        };
    }
}
