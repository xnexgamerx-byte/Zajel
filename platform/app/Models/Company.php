<?php

namespace App\Models;

use App\Models\Scopes\CurrentCompanyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'settings'      => 'array',
            'trial_ends_at' => 'datetime',
            'suspended_at'  => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new CurrentCompanyScope);

        static::creating(function (self $company) {
            $company->uuid ??= (string) Str::uuid();
        });
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasMany
    {
        return $this->subscriptions()->whereIn('status', ['trialing', 'active']);
    }

    /** حرف الشعار: أوّل حرفٍ من الاسم بعد «ال» — «ز» للزاجل، و«ب» للبرق. */
    public function initial(): string
    {
        return mb_substr((string) preg_replace('/^ال(?=\S)/u', '', trim((string) $this->name)), 0, 1);
    }

    public function isOperational(): bool
    {
        return in_array($this->status, ['trial', 'active'], true);
    }

    /** قراءة مفتاح من settings بمسار منقوط. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}
