<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قيدٌ في دفتر القيود: متوازن، بمفتاحٍ من مصدره، لا يُعدَّل ولا يُحذف — والتصحيح قيدٌ عكسي.
 * يكتبه App\Services\Finance\Journal وحده.
 */
class JournalEntry extends Model
{
    use AppendOnly, BelongsToCompany;

    public const UPDATED_AT = null;

    /** أسماء الأحداث التي ليست فئة حركةٍ قائمة */
    public const EVENTS = [
        'revenue'                  => 'إثبات إيراد الشحنة',
        'outside_courier'          => 'تحصيلٌ بلا مندوب مسجَّل',
        'expense_recorded'         => 'تسجيل مصروف',
        'expense_cancelled'        => 'إلغاء مصروف',
        'profit_withdrawal'        => 'سحب أرباح',
        'profit_withdrawal_cancel' => 'إلغاء سحب أرباح',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'created_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function eventLabel(): string
    {
        [$kind, $category] = array_pad(explode(':', $this->event, 2), 2, null);

        return match ($kind) {
            'merchant' => 'حساب تاجر: '.(Transaction::CATEGORIES[$category] ?? $category),
            'courier'  => 'حساب مندوب: '.(Transaction::CATEGORIES[$category] ?? $category),
            'cash'     => 'صندوق: '.CashMovement::labelFor((string) $category),
            default    => self::EVENTS[$this->event] ?? $this->event,
        };
    }
}
