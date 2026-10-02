<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * إيصال «استلام أجور مدفوعة مقدّماً»: ما قبضناه من تاجرٍ عن أجور شحناتٍ يدفعها
 * حين يُرسلها، وفي أيّ صندوقٍ دخل — ReceivePrepaidFees. لقطةٌ لا تُعدَّل.
 */
class PrepaidReceipt extends Model
{
    use BelongsToCompany, SeenByBranch;

    protected $guarded = ['id'];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withTrashed();
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
