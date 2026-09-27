<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * لقطةٌ من «الموقف المالي» كما كان ساعة التُقطت — بيد موظّفٍ أو الجدول الليليّ.
 * لا تُعدَّل: قيمتها أنها لا تتغيّر حين يتغيّر ما بعدها.
 */
class FinancialSnapshot extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'figures' => 'array'];
    }

    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by_user_id');
    }

    public static function take(?User $actor = null): self
    {
        $position = app(\App\Services\Money\FinancialPosition::class)->now();

        return static::create([
            'taken_at'         => now(),
            'taken_by_user_id' => $actor?->id,
            'figures'          => $position['figures'] + ['payables_by_pickup' => $position['payables_by_pickup']],
            'total'            => $position['total'],
        ]);
    }
}
