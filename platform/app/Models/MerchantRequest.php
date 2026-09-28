<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * طلبٌ من التاجر عبر بوابته: «حاسبوني» (طلب دفع) أو «سلّموني راجعي» (طلب
 * كشف راجع). رقمه يُقرأ على الهاتف، ويُغلَق وحده حين يُبنى كشفه أو تُسلَّم
 * رواجعه — فلا يبقى طلبٌ معلّقاً وقد نُفّذ.
 */
class MerchantRequest extends Model
{
    use BelongsToCompany, SeenByBranch;

    public const TYPES = [
        'payment' => 'طلب دفع',
        'returns' => 'طلب كشف راجع',
    ];

    public const STATUSES = [
        'open'      => 'بانتظار المعالجة',
        'handled'   => 'تمّت معالجته',
        'cancelled' => 'ملغى',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'via_pickup_courier' => 'boolean',
            'handled_at'         => 'datetime',
            'cancelled_at'       => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(MerchantSettlement::class, 'merchant_settlement_id');
    }

    public function returnBatch(): BelongsTo
    {
        return $this->belongsTo(ReturnBatch::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('merchant_requests.status', 'open');
    }

    public function scopeOfType(Builder $q, string $type): Builder
    {
        return $q->where('merchant_requests.type', $type);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** فرعه فرعُ تاجره — SeenByBranch */
    protected function branchThrough(): ?string
    {
        return 'merchant';
    }
}
