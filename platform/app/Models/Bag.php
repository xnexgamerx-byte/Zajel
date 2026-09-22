<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Bag extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sealed_at' => 'datetime', 'received_at' => 'datetime', 'opened_at' => 'datetime'];
    }

    public function fromHub(): BelongsTo
    {
        return $this->belongsTo(Hub::class, 'from_hub_id');
    }

    public function toHub(): BelongsTo
    {
        return $this->belongsTo(Hub::class, 'to_hub_id');
    }

    public function shipments(): BelongsToMany
    {
        return $this->belongsToMany(Shipment::class, 'bag_shipments')
            ->withPivot(['added_at', 'removed_at']);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
