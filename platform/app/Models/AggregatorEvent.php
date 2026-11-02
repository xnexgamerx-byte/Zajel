<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** تحديثٌ وصلنا من نظام الوسيط كما وصل، وما فُعل به (docs/plan/104) */
class AggregatorEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const RESULTS = [
        'pending' => 'بانتظار التطبيق',
        'applied' => 'طُبّق',
        'recorded' => 'سُجّل',
        'waiting' => 'ينتظر إجراءً منّا',
        'unmatched' => 'لا شحنة له',
        'error' => 'تعذّر',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'deliver_done' => 'boolean', 'applied_at' => 'datetime'];
    }

    /** وقت التحديث عندهم (updated_at في ما أرسلوه)، ليُرى كم تأخّر وصوله إلينا */
    public function theirTime(): ?CarbonInterface
    {
        $value = $this->payload['updated_at'] ?? null;

        return is_string($value) && $value !== '' ? rescue(fn () => Carbon::parse($value), null, false) : null;
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
