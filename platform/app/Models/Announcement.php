<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    use BelongsToCompany, SeenByBranch;

    protected $guarded = ['id'];

    /** @var array<string, string> */
    public const AUDIENCES = [
        'delivery_couriers' => 'مناديب التوصيل',
        'pickup_couriers'   => 'مناديب الاستلام',
        'merchants'         => 'التجّار',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    public function audienceLabel(): string
    {
        return self::AUDIENCES[$this->audience] ?? $this->audience;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** ما زال يُعرَض: لم ينتهِ أجله. */
    public function scopeLive(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * الجماهير التي ينتمي إليها المستخدم.
     *
     * المندوب «كلاهما» يرى إعلانات الفريقين، ومندوب الاستلام لا يرى ما
     * يخصّ حاملي النقد. والجمهور يُقرأ من حال المستخدم اليوم لا يوم الإرسال.
     *
     * @return array<int, string>
     */
    public static function audiencesFor(User $user): array
    {
        if ($user->role === UserRole::Merchant) {
            return ['merchants'];
        }

        if ($user->role === UserRole::Courier && ($courier = $user->courier)) {
            return array_values(array_filter([
                $courier->delivers() ? 'delivery_couriers' : null,
                $courier->picks() ? 'pickup_couriers' : null,
            ]));
        }

        return [];
    }

    /**
     * ما يراه المستخدم: ما وُجِّه إلى جمهوره ولم ينتهِ أجله — ولو أُرسل قبل تعيينه.
     * وإعلان الفرع لتجّار فرعه ومناديبه، وإعلان الشركة (بلا فرع) للجميع.
     */
    public function scopeFor(Builder $q, User $user): Builder
    {
        $branch = match ($user->role) {
            UserRole::Merchant => $user->merchant?->branch_id,
            UserRole::Courier  => $user->courier?->branch_id,
            default            => null,
        };

        return $q->whereIn('audience', static::audiencesFor($user) ?: ['—'])
            ->where(fn (Builder $w) => $w->whereNull('branch_id')->when($branch, fn ($w) => $w->orWhere('branch_id', $branch)))
            ->live();
    }

    public function scopeUnreadBy(Builder $q, User $user): Builder
    {
        return $q->whereDoesntHave('reads', fn ($r) => $r->where('user_id', $user->id));
    }

    /**
     * كم يبلغ الإعلانُ الآن — للمقارنة بعدد مَن قرأ.
     *
     * المستخدمون الفعّالون الذين يحملون الدور، لا كل مَن في جدول المناديب:
     * مندوبٌ بلا حساب دخول لا يقرأ شيئاً ولا يُحسَب عليه أنه لم يقرأ.
     */
    public function reach(): int
    {
        $users = User::query()->where('is_active', true);
        $branch = fn ($q) => $this->branch_id ? $q->where('branch_id', $this->branch_id) : $q;

        return match ($this->audience) {
            'merchants' => $users->where('role', UserRole::Merchant)
                ->when($this->branch_id, fn ($u) => $u->whereHas('merchant', $branch))->count(),
            'delivery_couriers' => $users->where('role', UserRole::Courier)
                ->whereHas('courier', fn ($c) => $branch($c->delivering()->active()))->count(),
            'pickup_couriers' => $users->where('role', UserRole::Courier)
                ->whereHas('courier', fn ($c) => $branch($c->picking()->active()))->count(),
            default => 0,
        };
    }
}
