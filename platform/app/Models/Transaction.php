<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\FitsColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دفتر الحركات — لا يُعدَّل ولا يُحذف صفّ منه.
 * التصحيح يكون بحركة معاكسة، وهذا ما يجعل الحساب قابلاً للتدقيق.
 */
class Transaction extends Model
{
    use AppendOnly, BelongsToCompany, FitsColumns;

    /**
     * أنواع القيد بالعربية. الشاشتان اللتان تعرضانها كتبتا قائمتيهما قبل
     * «تصحيح المبلغ»، فظهر «amount_correction» كما خُزِّن في كشف المندوب.
     * ويُلزم ArabicLabelsTest كل نوعٍ يقيّده الدفتر بأن يكون هنا.
     */
    public const CATEGORIES = [
        'shipment_due'      => 'مستحقّ شحنة',
        'return_fee'        => 'أجرة راجع',
        'amount_correction' => 'تصحيح مبلغ',
        'payout'            => 'دفع للتاجر',
        'cod_collected'     => 'تحصيل نقد',
        'commission'        => 'عمولة',
        'commission_paid'   => 'دفع عمولة',
        'cash_handover'     => 'تسليم نقد',
        'deduction'         => 'خصم',
    ];

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? (string) $this->category;
    }

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** نصٌّ مُركَّب يُقصّ على عموده بدل أن يُسقط الحفظ — FitsColumns */
    protected array $fits = ['description' => 255];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** الشحنة التي نتجت عنها الحركة — كشف الحساب يربط كل سطر بسببه. */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function scopeForAccount(Builder $q, string $type, int $id): Builder
    {
        return $q->where('account_type', $type)->where('account_id', $id);
    }

    /** المبلغ بإشارته من منظور صاحب الحساب. */
    public function signedAmount(): int
    {
        return $this->direction === 'credit' ? $this->amount : -$this->amount;
    }
}
