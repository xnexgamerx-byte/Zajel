<?php

namespace App\Actions\Notify;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * الإشعارات الجماعية.
 *
 * التسليم داخل التطبيق: شارةٌ على تطبيق المندوب وبوّابة التاجر، وقائمةٌ
 * تُفتح فتُسجَّل قراءتها. والدفع إلى الهاتف (push) يحتاج مزوّداً وجداول
 * devices قائمةٌ له — يُضاف فوق هذا ولا يُغيّره: الإعلان والقراءة هما
 * السجلّ، والدفع قناةٌ إليه.
 */
class Announce
{
    public function publish(string $audience, string $title, string $body, ?User $actor = null, ?\DateTimeInterface $expiresAt = null): Announcement
    {
        if (! array_key_exists($audience, Announcement::AUDIENCES)) {
            throw ValidationException::withMessages(['audience' => 'جمهورٌ غير معروف.']);
        }

        if ($expiresAt !== null && $expiresAt <= now()) {
            throw ValidationException::withMessages(['expires_at' => 'إعلانٌ ينتهي قبل أن يُرسَل لا يراه أحد.']);
        }

        return Announcement::create([
            'audience'           => $audience,
            'title'              => trim($title),
            'body'               => trim($body),
            'expires_at'         => $expiresAt,
            'created_by_user_id' => $actor?->id,
        ]);
    }

    /**
     * يُسجّل قراءة ما عُرض على المستخدم.
     *
     * فتح القائمة قراءةٌ لما فيها — لا زرّ «قرأتُ» يُنسى. والقراءة مرّة
     * واحدة: الفتح الثاني لا يُغيّر وقت الأولى.
     *
     * @param  Collection<int, Announcement>  $shown
     */
    public function markRead(Collection $shown, User $user): void
    {
        $unread = $shown->filter(fn (Announcement $a) => ! $a->relationLoaded('reads')
            || $a->reads->where('user_id', $user->id)->isEmpty());

        foreach ($unread as $announcement) {
            try {
                AnnouncementRead::firstOrCreate(
                    ['announcement_id' => $announcement->id, 'user_id' => $user->id],
                    ['read_at' => now()],
                );
            } catch (UniqueConstraintViolationException) {
                // فُتحت القائمة في نافذتين معاً: القراءة الأولى تكفي
            }
        }
    }
}
