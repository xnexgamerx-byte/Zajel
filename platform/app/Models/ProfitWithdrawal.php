<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سحبٌ من أرباح الشركة (docs/plan/68 §١٠): عمليةٌ منفصلة عن تحصيل المناديب، برقمها (WD-0001)،
 * بمسار طلبٍ ← اعتمادٍ ← دفعٍ من صندوقٍ أو حسابٍ مصرفيّ. والمستفيد في withdrawn_by، والسبب في note.
 */
class ProfitWithdrawal extends Model
{
    use BelongsToCompany;

    public const STATUSES = [
        'requested' => 'مطلوب',
        'approved'  => 'معتمد',
        'paid'      => 'مدفوع',
        'cancelled' => 'ملغى',
        'reversed'  => 'معكوس',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'withdrawn_on' => 'date',
            'cancelled_at' => 'datetime',
            'approved_at'  => 'datetime',
            'paid_at'      => 'datetime',
        ];
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /** لا مال فيه الآن: أُلغي قبل الدفع، أو عُكس بعده */
    public function isCancelled(): bool
    {
        return in_array($this->status, ['cancelled', 'reversed'], true);
    }
}
