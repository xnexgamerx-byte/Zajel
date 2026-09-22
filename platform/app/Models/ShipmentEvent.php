<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجلّ إضافة فقط. لا updated_at، ولا تعديل، ولا حذف —
 * وعليه يُبنى تتبّع الزبون وتقارير الأداء ومطابقة الحسابات.
 */
class ShipmentEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meta'       => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function failureReason(): BelongsTo
    {
        return $this->belongsTo(FailureReason::class);
    }
}
