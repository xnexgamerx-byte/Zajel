<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * «إيصال راجع مندوب»: رواجع مندوبٍ استُلمت منه بالمخزن مرّةً واحدة بإيصالٍ واحد
 * (ReceiveReturns) — كإيصال الراجع للتاجر من الجهة الأخرى: ما سلّمه المندوب ومن استلمه ومتى.
 */
class CourierReturnReceipt extends Model
{
    use BelongsToCompany, SeenByBranch;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /** فرعه فرعُ مندوبه — SeenByBranch */
    protected function branchThrough(): ?string
    {
        return 'courier';
    }
}
