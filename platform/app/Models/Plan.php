<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['features' => 'array', 'is_active' => 'boolean', 'commission_percent' => 'float'];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function has(string $feature): bool
    {
        return (bool) data_get($this->features ?? [], $feature, false);
    }
}
