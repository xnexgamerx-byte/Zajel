<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** نسخةٌ من تقرير فترةٍ مُقفَلة: الأولى عند الإقفال، وما بعدها بتعديلاتٍ لاحقة — ولا تتغيّر نسخةٌ صدرت */
class FinancialPeriodReport extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['figures' => 'array', 'adjustments' => 'array', 'issued_at' => 'datetime'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class, 'financial_period_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }
}
