<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use App\Support\ReceiptCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantSettlement extends Model
{
    use BelongsToCompany, SeenByBranch;

    protected $guarded = ['id'];

    /** رمز الاستلام يُطبع على وصل الحساب وحده: لا يخرج في JSON البوابة ولا التطبيق (docs/plan/69) */
    protected $hidden = ['receipt_code'];

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->receipt_code ??= ReceiptCode::generate());
    }

    /** رمز ورقته كما يُطبع — ويُولَّد لورقةٍ سبقت الرموز */
    public function receiptCode(): string
    {
        if (! $this->receipt_code) {
            $this->forceFill(['receipt_code' => ReceiptCode::generate()])->saveQuietly();
        }

        return $this->receipt_code;
    }

    protected function casts(): array
    {
        return [
            'from_date'    => 'date',
            'to_date'      => 'date',
            'confirmed_at' => 'datetime',
            'paid_at'      => 'datetime',
            'cancelled_at' => 'datetime',
            'merchant_confirmed_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** من أقفل الكشف */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MerchantSettlementShipment::class);
    }

    /** ما ليس مسودّةً لا يُعدَّل ولا يُقفَل ثانيةً: المُقفَل والمدفوع والملغى (docs/plan/38) */
    public function isLocked(): bool
    {
        return $this->status !== 'draft';
    }
}
