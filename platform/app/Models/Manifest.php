<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Manifest extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['departed_at' => 'datetime', 'arrived_at' => 'datetime'];
    }

    public function fromHub(): BelongsTo
    {
        return $this->belongsTo(Hub::class, 'from_hub_id');
    }

    public function toHub(): BelongsTo
    {
        return $this->belongsTo(Hub::class, 'to_hub_id');
    }

    public function bags(): BelongsToMany
    {
        return $this->belongsToMany(Bag::class, 'manifest_bags')
            ->withPivot(['loaded_at', 'unloaded_at', 'is_missing']);
    }
}
