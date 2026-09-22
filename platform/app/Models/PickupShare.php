<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickupShare extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['objected_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function pickupRequest(): BelongsTo
    {
        return $this->belongsTo(PickupRequest::class);
    }

    public function scopeObjected(Builder $q): Builder
    {
        return $q->where('status', 'objected');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'accrued'  => 'محتسبة',
            'objected' => 'معترَض عليها',
            'adjusted' => 'عُدّلت',
            'rejected' => 'رُفض الاعتراض',
            default    => $this->status,
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            'accrued'  => 'chip-ok',
            'objected' => 'chip-warn',
            'adjusted' => 'chip-info',
            'rejected' => 'chip-mute',
            default    => 'chip-mute',
        };
    }

    /** ما استقرّ عليه الأمر بعد الاعتراض. */
    public function finalAmount(): int
    {
        return (int) $this->amount + (int) $this->adjustment;
    }
}
