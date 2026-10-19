<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * فترةٌ محاسبية للشركة كلّها (docs/plan/68 §٩): تُعَدّ بفحوصها واستثناءاتها، ثم تُعتمد فتُقفَل
 * ويُحفظ تقريرها. وما يمسّها بعدُ يُكتب في الفترة المفتوحة ويُشير إليها.
 */
class FinancialPeriod extends Model
{
    use BelongsToCompany;

    public const STATUSES = [
        'pending_approval' => 'بانتظار الاعتماد',
        'closed'           => 'مُقفَلة',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_date'   => 'date',
            'to_date'     => 'date',
            'prepared_at' => 'datetime',
            'approved_at' => 'datetime',
            'figures'     => 'array',
            'checks'      => 'array',
            'exceptions'  => 'array',
        ];
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(FinancialPeriodReport::class)->orderBy('version');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function label(): string
    {
        return $this->from_date->format('Y-m-d').' — '.$this->to_date->format('Y-m-d');
    }
}
