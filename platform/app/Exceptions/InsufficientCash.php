<?php

namespace App\Exceptions;

use App\Models\CashBox;
use Illuminate\Validation\ValidationException;

/**
 * نقدٌ يخرج من صندوقٍ ليس فيه ما يكفيه.
 *
 * الصندوق درجٌ حقيقيّ: لا يُدفع منه أكثر ممّا فيه. ورصيدٌ تحت الصفر يعني أن
 * النظام صار يخالف عدّ اليد، فيُمنَع الخروج كلّه ويُقال لماذا، ولا يُكتب
 * شيء (CashBook::post).
 *
 * يرث خطأ التحقّق: الشاشة ترجع بالرسالة في أعلاها كأيّ خطأ في نموذج.
 */
final class InsufficientCash extends ValidationException
{
    public CashBox $box;

    public int $available = 0;

    public int $requested = 0;

    public string $category = '';

    public static function for(CashBox $box, int $available, int $requested, string $category): self
    {
        $e = self::withMessages(['cash_box' => self::sentence($box, $available, $requested, $category)]);

        $e->box = $box;
        $e->available = $available;
        $e->requested = $requested;
        $e->category = $category;

        return $e;
    }

    public static function sentence(CashBox $box, int $available, int $requested, string $category): string
    {
        $what = match ($category) {
            'merchant_payout'       => 'عملية التسديد',
            'expense'               => 'دفع المصروف',
            'commission_paid'       => 'دفع العمولة',
            'merchant_deposit'      => 'ردّ التأمين',
            'transfer_out'          => 'المناقلة',
            'branch_remittance_out' => 'التسديد للفرع',
            default                 => 'العملية',
        };

        $have = $available < 0
            ? 'رصيد «'.$box->name.'» تحت الصفر بـ'.number_format(-$available).' د.ع'
            : 'في «'.$box->name.'» '.number_format($available).' د.ع';

        // المناقلة والتسديد للفرع يخرجان ممّا في الدرج أصلاً: لا يُطلب لهما تحصيل
        $advice = in_array($category, ['transfer_out', 'branch_remittance_out'], true)
            ? ''
            : ' يرجى تحصيل المبالغ المستحقة من المندوبين أولًا.';

        return "لا يمكن إتمام {$what}، رصيد الصندوق غير كافٍ.{$advice} ({$have}، والمطلوب "
            .number_format($requested).' د.ع.)';
    }
}
