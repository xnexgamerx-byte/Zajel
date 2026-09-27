<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «دفعة ربح لمندوب الاستلام»: ما استحقّه، وحصّة المركز منه إن كان شريكاً،
 * وما دُفع له فعلاً ومن أيّ صندوق — لقطةٌ لا تتغيّر إن تغيّرت الشراكة غداً.
 */
class PickupPayout extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    public function centreLabel(): string
    {
        return match ($this->centre_type) {
            'percent' => $this->centre_value.'٪',
            'amount'  => 'مبلغ ثابت',
            default   => 'بلا شراكة',
        };
    }
}
