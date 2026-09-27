<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تسديدٌ من فرعٍ لآخر: ما جمعه من شحنات تجّار الآخر يُرسَل من صندوقه، ويُستلم
 * في صندوق الآخر بمبلغه الفعليّ — والفرق إن وُجد بملاحظته.
 */
class BranchRemittance extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id')->withTrashed();
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id')->withTrashed();
    }

    public function fromBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'from_box_id');
    }

    public function toBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'to_box_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('branch_remittances.status', 'sent');
    }

    /** للمقيَّد بفرع: ما خرج من فرعه أو دخله */
    public function scopeTouching(Builder $q, ?int $branchId): Builder
    {
        return $branchId === null ? $q
            : $q->where(fn ($w) => $w->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId));
    }

    public function difference(): ?int
    {
        return $this->received_amount === null ? null : (int) $this->received_amount - (int) $this->amount;
    }
}
