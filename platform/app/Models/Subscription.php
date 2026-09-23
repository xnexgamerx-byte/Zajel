<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at'          => 'date',
            'ends_at'            => 'date',
            'cancelled_at'       => 'datetime',
            'auto_renew'         => 'boolean',
            'commission_percent' => 'float',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isLive(): bool
    {
        return in_array($this->status, ['trialing', 'active'], true)
            && $this->ends_at?->endOfDay()->isFuture();
    }

    public function daysRemaining(): int
    {
        return max(0, $this->daysUntilEnd());
    }

    /**
     * الأيام حتى النهاية بإشارتها: سالبٌ لما انتهى.
     *
     * daysRemaining تقصّ السالب إلى صفر — فاشتراكٌ انتهى قبل عشرة أيام
     * كان يُعرض «ينتهي اليوم». وهذا يُبقي الماضي ماضياً.
     */
    public function daysUntilEnd(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->ends_at->copy()->startOfDay(), false);
    }

    /** موضعه على خطّ الانتهاء: lapsed (انتهى ولم يُجدَّد)، today، soon. */
    public function expiryState(): string
    {
        return match (true) {
            $this->daysUntilEnd() < 0   => 'lapsed',
            $this->daysUntilEnd() === 0 => 'today',
            default                     => 'soon',
        };
    }
}
