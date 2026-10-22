<?php

namespace App\Http\Controllers\Api\CallCenter;

use App\Actions\Support\Claims;
use App\Models\User;
use App\Models\WorkClaim;

/**
 * ما تشترك فيه شاشات تطبيق الكول سنتر (docs/plan/73): الحجز كما يُعرض، والانتظار بالدقائق.
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
}
