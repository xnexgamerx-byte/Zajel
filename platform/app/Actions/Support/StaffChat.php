<?php

namespace App\Actions\Support;

use App\Enums\UserRole;
use App\Models\Rank;
use App\Models\Shipment;
use App\Models\StaffMessage;
use App\Models\StaffThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * مراسلة الموظّفين داخل النظام (docs/plan/41).
 *
 * القسم مرتبةُ الموظّف في الشركة («موظّف الراجع»، «الحسابات»)، وموظّفٌ بلا مرتبة قسمُه
 * دوره («محاسب»). لكل قسمٍ محادثةٌ واحدة يراها كل من فيه، ولكل موظّفَين محادثة.
 * وغير المقروء لكل موظّفٍ وحده: ما كُتب بعد آخر قراءته، لا ما كتبه هو.
 */
class StaffChat
{
    /** قسم الموظّف: «rank:5»، أو «role:accountant» إن لم تكن له مرتبة */
    public static function teamOf(User $user): string
    {
        return $user->rank_id !== null ? 'rank:'.$user->rank_id : 'role:'.$user->role->value;
    }

    /**
     * الأقسام التي يُراسَل إليها: مراتب الشركة، وأدوار موظّفين بلا مرتبة.
     *
     * @return array<string, string>
     */
    public function teams(): array
    {
        $teams = Rank::query()->orderBy('name')->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id) => ['rank:'.$id => $name])->all();

        $roles = $this->staff()->whereNull('rank_id')->distinct()->pluck('role');

        foreach ($roles as $role) {
            $role = $role instanceof UserRole ? $role : UserRole::from($role);
            $teams['role:'.$role->value] = $role->label();
        }

        return $teams;
    }

    public function teamName(string $team): string
    {
        return $this->teams()[$team] ?? 'قسمٌ لم يعد موجوداً';
    }

    /**
     * موظّفو الشركة النشطون: لا تاجر ولا مندوب. وموظّف الفرع يرى بالاسم موظّفي فرعه وحدهم —
     * عزل الفروع — ويبلغ غيرهم بأقسامهم: «قسم الراجع» يصل الفرع الرئيسي.
     */
    public function staff(?User $viewer = null): Builder
    {
        return User::query()->where('is_active', true)
            ->whereNotIn('role', [UserRole::Merchant->value, UserRole::Courier->value])
            ->when($viewer?->isBranchLimited(), fn (Builder $q) => $q->where('branch_id', $viewer->branch_id));
    }

    /** محادثاتٌ يراها $user: هو فيها، أو هي محادثة قسمه */
    public function visibleTo(User $user): Builder
    {
        return StaffThread::query()->where(fn (Builder $q) => $q
            ->where('team', self::teamOf($user))
            ->orWhereHas('members', fn (Builder $m) => $m->where('users.id', $user->id)));
    }

    /** ما لم يقرأه $user: سطرٌ جاء بعد آخر قراءته */
    public function unread(User $user): Builder
    {
        return $this->visibleTo($user)
            ->whereNotNull('last_message_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('staff_thread_members')
                ->whereColumn('staff_thread_members.staff_thread_id', 'staff_threads.id')
                ->where('staff_thread_members.user_id', $user->id)
                ->whereNotNull('staff_thread_members.last_read_id')
                ->whereColumn('staff_thread_members.last_read_id', '>=', 'staff_threads.last_message_id'));
    }

    public function unreadCount(User $user): int
    {
        return $this->unread($user)->count();
    }

    /** محادثة موظّفَين: واحدةٌ بينهما، تُنشأ أوّل مرّة */
    public function between(User $one, User $other): StaffThread
    {
        $thread = StaffThread::query()->whereNull('team')
            ->whereHas('members', fn (Builder $m) => $m->where('users.id', $one->id))
            ->whereHas('members', fn (Builder $m) => $m->where('users.id', $other->id))
            ->first();

        if ($thread) {
            return $thread;
        }

        return DB::transaction(function () use ($one, $other) {
            $thread = StaffThread::create(['team' => null]);
            $thread->members()->attach(array_unique([$one->id, $other->id]));

            return $thread;
        });
    }

    /** محادثة القسم: واحدةٌ له */
    public function forTeam(string $team): StaffThread
    {
        if (! array_key_exists($team, $this->teams())) {
            throw ValidationException::withMessages(['to' => 'القسم غير موجود.']);
        }

        return StaffThread::firstOrCreate(['team' => $team]);
    }

    public function send(StaffThread $thread, string $body, User $actor, ?Shipment $shipment = null): StaffMessage
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'لا تُرسَل رسالة فارغة.']);
        }

        return DB::transaction(function () use ($thread, $body, $actor, $shipment) {
            $thread = StaffThread::query()->lockForUpdate()->findOrFail($thread->id);

            $message = StaffMessage::create([
                'staff_thread_id' => $thread->id,
                'user_id'         => $actor->id,
                'author_name'     => $actor->name,
                'shipment_id'     => $shipment?->id,
                'body'            => mb_substr($body, 0, 2000),
                'created_at'      => now(),
            ]);

            $thread->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])->save();

            // من كتب لقسمٍ يبقى في محادثته ليرى الردّ، وما كتبه مقروءٌ عنده
            $this->markRead($thread, $actor);

            return $message;
        });
    }

    /**
     * فتحُ المحادثة قراءتُها حتى آخر رسالةٍ رآها — ولا يرجع إلى الوراء إن جاءت أحدث منها.
     * ومن يفتح محادثة قسمه يصير فيها.
     */
    public function markRead(StaffThread $thread, User $user): void
    {
        $seen = (int) $thread->last_message_id;
        $member = ['staff_thread_id' => $thread->id, 'user_id' => $user->id];

        if (! DB::table('staff_thread_members')->where($member)->exists()) {
            DB::table('staff_thread_members')->insert($member + ['last_read_id' => $seen ?: null]);

            return;
        }

        DB::table('staff_thread_members')->where($member)
            ->where(fn ($q) => $q->whereNull('last_read_id')->orWhere('last_read_id', '<', $seen))
            ->update(['last_read_id' => $seen ?: null]);
    }
}
