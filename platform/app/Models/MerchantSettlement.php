<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantSettlement extends Model
{
    use BelongsToCompany, SeenByBranch;

    protected $guarded = ['id'];

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
