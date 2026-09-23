<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'staff_unread'    => 'boolean',
            'merchant_unread' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** الكرة عندنا: كتب التاجر آخر سطر والمحادثة مفتوحة. */
    public function awaitsUs(): bool
    {
        return $this->isOpen() && $this->last_author === 'merchant';
    }

    /**
     * ما يراه الموظّف: الموظّف المقيَّد بفرعٍ يرى محادثات تجّار فرعه —
     * كما يرى شحناتهم وحدها (Shipment::visibleTo).
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->isBranchLimited()) {
            return $q->whereHas('merchant', fn ($m) => $m->where('branch_id', $user->branch_id));
        }

        return $q;
    }
}
