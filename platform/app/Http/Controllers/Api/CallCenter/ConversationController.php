<?php

namespace App\Http\Controllers\Api\CallCenter;

use App\Actions\Support\Claims;
use App\Actions\Support\Converse;
use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Support\FeatureGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * محادثات التجّار في تطبيق الكول سنتر (docs/plan/73): ما ينتظر ردّنا الأقدم أوّلاً، ومن فتحت
 * المحادثة تردّ عليها وحدها — بـConverse نفسه الذي في الموقع.
 */
class ConversationController extends Controller
{
    use Concerns;

    public function __construct(protected Converse $converse) {}

    public function index(Request $request): JsonResponse
    {
        $this->gate($request);
        $waiting = $request->query('show') !== 'open';

        $list = Conversation::query()->visibleTo($request->user())->where('status', 'open')
            ->when($waiting, fn ($q) => $q->where('last_author', 'merchant'))
            ->with(['merchant:id,business_name', 'shipment:id,number'])
            ->orderBy('last_message_at', $waiting ? 'asc' : 'desc')
            ->limit(100)->get();
        $claims = Claims::holders(Claims::CONVERSATION, $list->pluck('id'));

        return response()->json([
            'data' => $list->map(fn (Conversation $c) => $this->row($c, $claims[$c->id] ?? null, $request))->values(),
        ]);
    }

    /** تُفتح فتصير لها إن لم تكن لزميلتها — وتُقرأ على كلّ حال */
    public function show(Request $request, int $conversation): JsonResponse
    {
        $c = $this->find($request, $conversation);
        $c->load(['messages' => fn ($q) => $q->reorder()->latest('id')->limit(100)]);
        $this->converse->markRead($c, Converse::STAFF);

        $claim = Claims::holder(Claims::CONVERSATION, $c->id);
        if ($claim === null && $c->isOpen()) {
            $claim = Claims::take(Claims::CONVERSATION, $c->id, $request->user())->load('user:id,name');
        }

        return response()->json(['data' => $this->row($c, $claim, $request) + [
            'messages' => $c->messages->sortBy('id')->values()->map(fn ($m) => self::messageJson($m)),
        ]]);
    }

    public function reply(Request $request, int $conversation): JsonResponse
    {
        $c = $this->find($request, $conversation);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']], [], ['body' => 'الردّ']);
        Claims::take(Claims::CONVERSATION, $c->id, $request->user());
        $this->converse->reply($c, $data['body'], $request->user(), Converse::STAFF);

        return response()->json(['message' => 'وصل ردّك للتاجر.'], 201);
    }

    /** «أغلقها»: انتهى ما فيها — والحجز معها */
    public function close(Request $request, int $conversation): JsonResponse
    {
        $c = $this->find($request, $conversation);
        Claims::take(Claims::CONVERSATION, $c->id, $request->user());
        $this->converse->close($c);
        Claims::clear(Claims::CONVERSATION, $c->id);

        return response()->json(['message' => 'أُغلقت المحادثة.']);
    }

    public function release(Request $request, int $conversation): JsonResponse
    {
        $c = $this->find($request, $conversation);
        Claims::release(Claims::CONVERSATION, $c->id, $request->user());

        return response()->json(['message' => 'أفلتّها — تردّ عليها أيّ زميلة.']);
    }

    private function gate(Request $request): void
    {
        abort_unless(FeatureGate::enabled(Feature::Conversations) && $request->user()->can('support.reply'), 403);
    }

    /** صورة التاجر أو ملفّه — لمن ترى المحادثة */
    public function file(Request $request, int $conversation, int $message): StreamedResponse
    {
        $c = $this->find($request, $conversation);

        return $c->messages()->whereKey($message)->firstOrFail()->attachmentResponse();
    }

    private function find(Request $request, int $id): Conversation
    {
        $this->gate($request);

        return Conversation::query()->visibleTo($request->user())
            ->with(['merchant:id,business_name,phone', 'shipment:id,number'])->findOrFail($id);
    }

    private function row(Conversation $c, $claim, Request $request): array
    {
        return [
            'id' => $c->id,
            'subject' => $c->subject,
            'merchant' => ['name' => $c->merchant?->business_name, 'phone' => $c->merchant?->phone],
            'shipment' => $c->shipment?->number,
            'open' => $c->isOpen(),
            'waiting' => $c->isOpen() && $c->last_author === 'merchant',
            'unread' => (bool) $c->staff_unread,
            'waiting_minutes' => self::minutesSince($c->last_message_at),
            'claim' => self::claimJson($claim, $request->user()),
        ];
    }
}
