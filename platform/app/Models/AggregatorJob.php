<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ما يُرسَل لنظام الوسيط بالمجدوِل (docs/plan/104): إنشاء طلبٍ، أو استلام راجع. ما فشل يُعاد
 * بمهلةٍ تطول، وبعد MAX_ATTEMPTS يقف «فشل» حتى يعيده موظّفٌ من شاشة الربط.
 */
class AggregatorJob extends Model
{
    use BelongsToCompany;

    public const MAX_ATTEMPTS = 6;

    public const ACTIONS = [
        'create' => 'إنشاء الطلب عندهم',
        'receive_return' => 'استلام الراجع',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function label(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}
