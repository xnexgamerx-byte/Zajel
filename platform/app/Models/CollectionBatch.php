<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * «وجبة تحصيل» (COL-0001): كشوف مناديب مُقفَلة في مدّةٍ لفرع، بأرقامها مفصولة —
 * أموال التجّار، وأجور التوصيل (إيراد الشركة)، والعمولات والتكاليف، وصافي الربح.
 * المسودّة تُعدَّل، والمعتمدة تُصحَّح بسببٍ يُكتب في سجلّها.
 */
class CollectionBatch extends Model
{
    use BelongsToCompany, SeenByBranch;

    /** المدد كما يختارها المدير: أسبوع، ١٥ يوماً، شهر، أو ما يحدّده */
    public const PERIODS = [
        'week'   => ['أسبوعية', 7],
        'half'   => ['كل ١٥ يوماً', 15],
        'month'  => ['شهرية', 30],
        'custom' => ['فترة أحدّدها', null],
    ];

    /** حالات الوجبة بترتيبها (docs/plan/68 §٧) */
    public const STATUSES = [
        'draft'                   => 'مسودة',
        'awaiting_cash'           => 'بانتظار استلام النقد',
        'awaiting_reconciliation' => 'بانتظار المطابقة',
        'discrepancy'             => 'تحتوي على فروقات',
        'approved'                => 'مطابقة ومعتمدة',
        'cancelled'               => 'ملغاة',
    ];

    /** لم تُعتمد ولم تُلغَ: «ضمن» لا «ليس ضمن»، فيُستعمل الفهرس */
    public const OPEN = ['draft', 'awaiting_cash', 'awaiting_reconciliation', 'discrepancy'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_date'   => 'date',
            'to_date'     => 'date',
            'approved_at'  => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CollectionBatchFile::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CollectionBatchItem::class);
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(CollectionBatchCost::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(CollectionBatchAudit::class);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /** لم تُعتمد ولم تُلغَ: تُعدَّل */
    public function isOpen(): bool
    {
        return ! $this->isApproved() && ! $this->isCancelled();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function periodLabel(): string
    {
        return self::PERIODS[$this->period][0] ?? 'فترة أحدّدها';
    }

    /** حال المطابقة: ما عُدَّ يساوي المتوقَّع، أو عجزٌ، أو زيادة */
    public function reconciliationLabel(): string
    {
        return match (true) {
            $this->discrepancy < 0 => 'عجز '.number_format(-$this->discrepancy),
            $this->discrepancy > 0 => 'زيادة '.number_format($this->discrepancy),
            default                => 'مطابقة',
        };
    }

    /** ما لم يُحصَّل من النقد بعدُ: الربح المحاسبيّ ناقص المتحقّق */
    public function unrealized(): int
    {
        return (int) $this->net_profit - (int) $this->realized_profit;
    }
}
