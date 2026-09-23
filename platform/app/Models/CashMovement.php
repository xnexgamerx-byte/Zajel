<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\FitsColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    use AppendOnly, BelongsToCompany, FitsColumns;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** نصٌّ مُركَّب يُقصّ على عموده بدل أن يُسقط الحفظ — FitsColumns */
    protected array $fits = ['description' => 255];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            'opening'          => 'رصيد افتتاحي',
            'courier_handover' => 'تسليم نقد من مندوب',
            'merchant_payout'  => 'دفع لتاجر',
            'commission_paid'  => 'عمولة مندوب',
            'merchant_deposit' => 'تأمين تاجر',
            'expense'          => 'مصروف',
            'transfer_in'      => 'مناقلة واردة',
            'transfer_out'     => 'مناقلة صادرة',
            'adjustment'       => 'تسوية جرد',
            default            => $this->category,
        };
    }
}
