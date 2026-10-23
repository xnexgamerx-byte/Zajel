<?php

namespace App\Http\Controllers\Api\CallCenter;

use App\Actions\Support\Claims;
use App\Actions\Support\Converse;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Models\WorkClaim;

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
