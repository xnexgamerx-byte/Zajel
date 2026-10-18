<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سحبٌ من أرباح الشركة: عمليةٌ منفصلة عن تحصيل المناديب، برقمها (WD-0001)، ومن صندوقٍ إن اختير.
 */
class ProfitWithdrawal extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'withdrawn_on' => 'date',
            'cancelled_at' => 'datetime',
        ];
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

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
