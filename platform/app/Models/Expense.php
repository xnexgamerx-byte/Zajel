<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'spent_on'     => 'date',
            'paid_at'      => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'recorded');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'recorded'  => 'مسجَّل',
            'paid'      => 'مدفوع',
            'cancelled' => 'ملغى',
            default     => $this->status,
        };
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['paid', 'cancelled'], true);
    }
}
