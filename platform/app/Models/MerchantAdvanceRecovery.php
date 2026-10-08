<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ما استُردّ من سلفة: من كشفٍ أُقفِل، أو نقداً سدّده التاجر. */
class MerchantAdvanceRecovery extends Model
{
    use AppendOnly, BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(MerchantAdvance::class, 'merchant_advance_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(MerchantSettlement::class, 'merchant_settlement_id');
    }
}
