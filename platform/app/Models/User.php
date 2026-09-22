<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Permissions\Ability;
use App\Models\Scopes\UserScope;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * صلاحيات هذا المستخدم فعلاً.
     *
     * التجاوز إن وُجد، وإلّا افتراضي دوره. فتغيير سياسة الدور يسري على
     * كل من لم يُخصَّص له شيء، ولا يُنسَخ الجدول في كل صفّ ليتقادم.
     *
     * @return array<int, string>
     */
    public function abilities(): array
    {
        if ($this->role->isPlatform()) {
            return Ability::all();
        }

        return is_array($this->permissions)
            ? array_values(array_intersect($this->permissions, Ability::all()))
            : Ability::defaultsFor($this->role);
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
