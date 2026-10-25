<?php

namespace App\Http\Controllers\Api\CallCenter;

use App\Actions\Support\Claims;
use App\Actions\Support\Converse;
use App\Actions\Support\CourierChat;
use App\Enums\Feature;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\CourierMessage;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WorkClaim;
use App\Support\FeatureGate;

/**
 * ما تشترك فيه شاشات تطبيق الكول سنتر (docs/plan/73): الحجز كما يُعرض، والانتظار بالدقائق، ورسالة
 * التاجر بصورتها.
 */
trait Concerns
{
    /** @return array{by: string, mine: bool, since: ?string, text: string}|null */
    protected static function claimJson(?WorkClaim $claim, User $user): ?array
    {
        return $claim === null ? null : [
            'by' => $claim->user?->name ?? 'زميلتك',
            'mine' => (int) $claim->user_id === (int) $user->id,
            'since' => $claim->claimed_at?->toIso8601String(),
            'text' => (int) $claim->user_id === (int) $user->id ? 'تعالجها أنت' : Claims::message($claim),
        ];
    }

    protected static function minutesSince(?\DateTimeInterface $at): int
    {
        return $at === null ? 0 : max(0, (int) floor((now()->getTimestamp() - $at->getTimestamp()) / 60));
    }

    /**
     * محادثتا الشحنة: التاجر عنها، ومندوبها عنها — بترتيب الكتابة. في المعالجة (docs/plan/73)، وفي كلّ
     * شحنةٍ تفتحها الموظّفة: قيد التوصيل والمؤجل وإعادة التوصيل وتذاكر المبلغ (docs/plan/81).
     *
     * @return array{can_chat: bool, merchant_chat: mixed, courier_chat: mixed}
     */
    protected static function shipmentChats(Shipment $s, User $user): array
    {
        $chat = FeatureGate::enabled(Feature::Conversations) && $user->can('support.reply');

        $conversation = $chat ? Conversation::query()->where('shipment_id', $s->id)->latest('id')
            ->with(['messages' => fn ($q) => $q->reorder()->latest('id')->limit(30)])->first() : null;
        $courier = $chat ? CourierMessage::query()->where('shipment_id', $s->id)->latest('id')->limit(30)->get()->sortBy('id')->values() : collect();

        return [
            'can_chat' => $chat,
            'merchant_chat' => $conversation ? $conversation->messages->sortBy('id')->values()->map(fn ($m) => self::messageJson($m)) : [],
            'courier_chat' => $courier->map(fn (CourierMessage $m) => self::courierLine($m))->values(),
        ];
    }

    protected static function courierLine(CourierMessage $m): array
    {
        return [
            'mine' => $m->author === CourierChat::STAFF,
            'author' => $m->author_name,
            'body' => $m->body,
            'shipment' => $m->relationLoaded('shipment') ? $m->shipment?->number : null,
            'at' => $m->created_at?->toIso8601String(),
        ];
    }

    /** ردٌّ على التاجر عن الشحنة: في محادثتها، أو محادثةٌ جديدة عنها */
    protected static function writeMerchant(Shipment $s, string $body, User $user): void
    {
        $converse = app(Converse::class);
        $open = Conversation::query()->where('shipment_id', $s->id)->latest('id')->first();

        if ($open) {
            $converse->reply($open, $body, $user, Converse::STAFF);
            $converse->markRead($open->refresh(), Converse::STAFF);
        } else {
            $converse->start($s->merchant, "بخصوص الوصل {$s->number}", $body, $user, Converse::STAFF, $s->number);
        }
    }

    /** ردٌّ على مندوبها: في محادثته، والسطر يحمل رقم الشحنة */
    protected static function writeCourier(Shipment $s, string $body, User $user): void
    {
        $chat = app(CourierChat::class);
        $chat->send($s->deliveryCourier, $body, $user, CourierChat::STAFF, $s);
        $chat->markRead($chat->thread($s->deliveryCourier)->refresh(), CourierChat::STAFF);
    }

    /** رسالةٌ في محادثة تاجر — وصورته تُعرض في المحادثة برمز الموظّفة (docs/plan/74) */
    protected static function messageJson(ConversationMessage $m): array
    {
        return [
            'mine' => $m->author === Converse::STAFF,
            'author' => $m->author_name,
            'body' => $m->body,
            'file' => $m->attachmentAvailable() ? [
                'name' => $m->attachment_name,
                'image' => $m->attachmentIsImage(),
                'size' => $m->attachmentSizeLabel(),
                'url' => route('api.callcenter.conversations.file', [$m->conversation_id, $m->id]),
            ] : null,
            'at' => $m->created_at?->toIso8601String(),
        ];
    }
}
