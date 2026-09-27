<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * «دفعة راجع»: رواجع تاجرٍ سُلّمت مرّةً واحدة بإيصالٍ واحد — من المخزن أو مع
 * مندوب الاستلام. الإيصال ما يوقّع عليه، والاستلام الفعليّ ما يُحسم به
 * «وين راجعي؟».
 */
class ReturnBatch extends Model
{
    use BelongsToCompany;

    public const VIA = [
        'store'          => 'من المخزن',
        'pickup_courier' => 'مع مندوب الاستلام',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['handed_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** مندوب الاستلام الذي حملها، إن حملها مندوب */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function handedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_by_user_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function viaLabel(): string
    {
        return $this->via === 'pickup_courier' && $this->courier
            ? 'مع '.$this->courier->name
            : (self::VIA[$this->via] ?? $this->via);
    }

    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }
}
