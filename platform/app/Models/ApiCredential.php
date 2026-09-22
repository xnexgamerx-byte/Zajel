<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiCredential extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'is_active' => 'boolean', 'last_used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
