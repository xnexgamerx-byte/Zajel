<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Permissions\Ability;
use App\Support\Username;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\UserScope;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * مستخدم واحد للجميع: النواة والشركات والمندوبين والتجّار.
 * company_id = null يعني مستخدم نواة، وهؤلاء يُقرأون في وضع النواة فقط.
 */
class User extends Authenticatable
{
    use BelongsToCompany, HasFactory, Notifiable, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'permissions'  => 'array',
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'role'              => UserRole::class,
        ];
    }

    /** بلا سياق شركة لا يوجد مستخدم — صفر صفوف لا استثناء. انظر UserScope. */
    protected static function companyScope(): Scope
    {
        return new UserScope;
    }

    /**
     * كل حسابٍ له اسم مستخدم يُدخَل به: المختار بصيغته المحفوظة، وإلّا رقم
     * هاتفه — فحسابٌ يُنشأ من أيّ مكان (مندوب، تاجر، أمر مدير المنصّة) يُدخَل
     * به فوراً. انظر App\Support\Username.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            $user->username = filled($user->username)
                ? Username::canonical($user->username)
                : Username::canonical($user->phone);
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function rank(): BelongsTo
    {
        // بمعرّفها من صفّه لا بالبحث: تُقرأ ولو خارج سياق شركته (أمرٌ في الطرفية)
        return $this->belongsTo(Rank::class)->withoutGlobalScope(CompanyScope::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(UserGrant::class)->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * صلاحيات هذا المستخدم فعلاً.
     *
     * أساسها بالترتيب: تخصيصه القديم إن وُجد (قبل المراتب، ويبقى حتى يُزال)،
     * ثم صاحب الشركة كل شيء — لا مرتبة تقيّده، فلا تُقفَل الشركة عن نفسها —
     * ثم مرتبته، ثم افتراضي دوره. وفوق الأساس صلاحياته الاستثنائية.
     *
     * فتغيير المرتبة أو سياسة الدور يسري على كل من يحملها، ولا يُنسَخ الجدول
     * في كل صفّ ليتقادم.
     *
     * @return array<int, string>
     */
    public function abilities(): array
    {
        if ($this->role->isPlatform()) {
            return Ability::all();
        }

        if (! $this->isStaff()) {
            return [];
        }

        $base = match (true) {
            is_array($this->permissions)            => $this->permissions,
            $this->role === UserRole::CompanyOwner  => Ability::all(),
            $this->rank_id !== null && $this->rank !== null => $this->rank->abilities ?? [],
            default                                 => Ability::defaultsFor($this->role),
        };

        return Ability::ordered([...$base, ...$this->grants->pluck('ability')]);
    }

    /** مصدر صلاحياته، للعرض: «مخصّصة»، أو اسم مرتبته، أو دوره. */
    public function abilitySource(): string
    {
        return match (true) {
            is_array($this->permissions)                    => 'تخصيصٌ قديم',
            $this->role === UserRole::CompanyOwner          => 'صاحب الشركة: كل شيء',
            $this->rank_id !== null && $this->rank !== null => $this->rank->name,
            default                                         => 'افتراضي «'.$this->role->label().'»',
        };
    }

    public function hasAbility(string $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    /** هل صلاحياته مخصَّصة أم افتراضي دوره؟ */
    public function hasCustomPermissions(): bool
    {
        return is_array($this->permissions);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** موظّف شركة — لا تاجر ولا مندوب. */
    public function isStaff(): bool
    {
        return ! in_array($this->role, [UserRole::Merchant, UserRole::Courier], true);
    }

    public function isPlatformUser(): bool
    {
        return $this->company_id === null && $this->role->isPlatform();
    }

    /** المستخدم المقيّد بفرع لا يرى شحنات الفروع الأخرى. */
    public function isBranchLimited(): bool
    {
        return $this->branch_id !== null
            && ! in_array($this->role, [UserRole::CompanyOwner, UserRole::CompanyAdmin], true);
    }
}
