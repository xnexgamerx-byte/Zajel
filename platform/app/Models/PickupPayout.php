<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «دفعة ربح لمندوب الاستلام»: ما استحقّه، وحصّة المركز منه إن كان شريكاً،
 * وما دُفع له فعلاً ومن أيّ صندوق — لقطةٌ لا تتغيّر إن تغيّرت الشراكة غداً.
 */
class PickupPayout extends Model
{
    use BelongsToCompany, SeenByBranch;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

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

    /** فرعه فرعُ مندوبه — SeenByBranch */
    protected function branchThrough(): ?string
    {
        return 'courier';
    }
}
