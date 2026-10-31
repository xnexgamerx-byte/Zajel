<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** تحديثٌ وصلنا من نظام الوسيط كما وصل، وما فُعل به (docs/plan/104) */
class AggregatorEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const RESULTS = [
        'applied' => 'طُبّق',
        'recorded' => 'سُجّل',
        'unmatched' => 'لا شحنة له',
        'error' => 'تعذّر',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'deliver_done' => 'boolean'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
