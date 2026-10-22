<?php

namespace App\Actions\Support;

use App\Models\User;
use App\Models\WorkClaim;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «من فتح الطلب يعالجه» (docs/plan/73): تذكرة تغيير المبلغ، وشحنة المعالجة، ومحادثة التاجر —
 * من فتحتها حجزتها، وزميلتها ترى «تعالجها فلانة» ولا تعالجها: لا اتّصالان بالزبون نفسه، ولا
 * قراران متعاكسان على الشحنة نفسها.
 *
 * الحجز يتجدّد بكلّ عملٍ عليه، ويُفلت وحده بعد ربع ساعةٍ بلا عمل (أغلقت الشاشة ومضت)، ويُرفع
 * بالقرار نفسه، أو بـ«أفلتها».
 */
final class Claims
{
    public const TICKET = 'ticket';

    public const PROCESSING = 'processing';

    public const CONVERSATION = 'conversation';

    /** ما بعده بلا عمل: الحجز ساقط، ويأخذه غيرها */
    public const IDLE_MINUTES = 15;

    /** الحاجزة الآن — إن كان حجزها قائماً */
    public static function holder(string $subject, int $id): ?WorkClaim
    {
        return self::active()->where('subject', $subject)->where('subject_id', $id)->with('user:id,name')->first();
    }

    /**
     * يحجزه لها أو يجدّد حجزها — ويرفض إن حجزته غيرها وحجزها قائم.
     *
     * @throws ValidationException «تعالجها فلانة»، على الحقل claim
     */
    public static function take(string $subject, int $id, User $user): WorkClaim
    {
        try {
            return DB::transaction(function () use ($subject, $id, $user) {
                $row = WorkClaim::query()->where('subject', $subject)->where('subject_id', $id)->lockForUpdate()->first();

                if ($row && (int) $row->user_id !== (int) $user->id && $row->seen_at->gt(self::since())) {
                    throw ValidationException::withMessages(['claim' => self::message($row->load('user:id,name'))]);
                }

                if ($row) {
                    $row->forceFill([
                        'claimed_at' => (int) $row->user_id === (int) $user->id ? $row->claimed_at : now(),
                        'user_id' => $user->id,
                        'seen_at' => now(),
                    ])->save();

                    return $row;
                }

                return WorkClaim::create([
                    'subject' => $subject, 'subject_id' => $id, 'user_id' => $user->id,
                    'claimed_at' => now(), 'seen_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // حجزتاه في اللحظة نفسها: يُقرأ صفّ الأولى ويُحكم به
            return self::take($subject, $id, $user);
        }
    }

    /** «أفلتها»: حجزها هي وحدها */
    public static function release(string $subject, int $id, User $user): bool
    {
        return WorkClaim::query()->where('subject', $subject)->where('subject_id', $id)
            ->where('user_id', $user->id)->delete() > 0;
    }

    /** حُسم الطلب: لا حجز بعده */
    public static function clear(string $subject, int $id): void
    {
        WorkClaim::query()->where('subject', $subject)->where('subject_id', $id)->delete();
    }

    /**
     * حاجزات قائمة لقائمةٍ من الطلبات، بأرقامها.
     *
     * @param  iterable<int>  $ids
     * @return Collection<int, WorkClaim>
     */
    public static function holders(string $subject, iterable $ids): Collection
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->values();

        return $ids->isEmpty() ? collect() : self::active()->where('subject', $subject)
            ->whereIn('subject_id', $ids)->with('user:id,name')->get()->keyBy('subject_id');
    }

    /** ما تعالجه هي الآن @return Collection<int, WorkClaim> */
    public static function mine(User $user): Collection
    {
        return self::active()->where('user_id', $user->id)->orderBy('claimed_at')->get();
    }

    public static function message(WorkClaim $claim): string
    {
        $minutes = max(1, (int) $claim->claimed_at->diffInMinutes(now()));

        return 'تعالجها '.($claim->user?->name ?? 'زميلتك')." منذ {$minutes} دقيقة — لا تُعالَج مرّتين.";
    }

    /** حجزٌ قائم لغيرها — لتُعرض القائمة بأسمائهن */
    public static function heldByOther(?WorkClaim $claim, User $user): bool
    {
        return $claim !== null && (int) $claim->user_id !== (int) $user->id;
    }

    private static function active()
    {
        return WorkClaim::query()->where('seen_at', '>', self::since());
    }

    private static function since(): Carbon
    {
        return now()->subMinutes(self::IDLE_MINUTES);
    }
}
