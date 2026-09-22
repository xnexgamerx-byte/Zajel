<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
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

    public function manifests(): BelongsToMany
    {
        return $this->belongsToMany(Manifest::class, 'manifest_bags')
            ->withPivot(['loaded_at', 'unloaded_at', 'is_missing']);
    }

    public function liveShipments(): BelongsToMany
    {
        return $this->shipments()->wherePivotNull('removed_at');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'open'       => 'مفتوح',
            'sealed'     => 'مختوم',
            'in_transit' => 'في الطريق',
            'received'   => 'وصل',
            'opened'     => 'فُتح',
            default      => $this->status,
        };
    }

    /** نبرة الشارة — مجموعة الحالات المحجوزة نفسها. */
    public function statusTone(): string
    {
        return match ($this->status) {
            'open'       => 'chip-warn',
            'sealed'     => 'chip-info',
            'in_transit' => 'chip-info',
            'received'   => 'chip-ok',
            'opened'     => 'chip-mute',
            default      => 'chip-mute',
        };
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'open');
    }
}
