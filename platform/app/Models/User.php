<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToCompany;
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
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'role'              => UserRole::class,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
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
