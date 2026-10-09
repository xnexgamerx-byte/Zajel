<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Merchant;

/**
 * طرق دفع مستحقّات التاجر التي تعرضها الشركة في «طلب محاسبة» (docs/plan/44): النقد — بيد مندوب
 * الاستلام أو من الشركة — والبطاقات والمحافظ. والشركة تُخفي من الإعدادات ما لا تتعامل به.
 */
final class PayoutMethods
{
    /** ما يحتاج تفاصيل يكتبها التاجر: كلّ ما سوى النقد */
    public const DETAILS_HINT = [
        'zaincash'      => 'رقم محفظة زين كاش واسم صاحبها',
        'asiahawala'    => 'رقم آسيا حوالة واسم صاحبه',
        'fastpay'       => 'رقم فاست باي واسم صاحبه',
        'qi'            => 'رقم بطاقة Qi (ماستر كارد) واسم صاحبها',
        'fib'           => 'رقم حساب FIB واسم صاحبه',
        'bank_transfer' => 'المصرف ورقم الحساب (IBAN) واسم صاحبه',
    ];

    /** @return array<string, string> ما تعرضه الشركة، بترتيبه */
    public static function offered(?Company $company = null): array
    {
        $company ??= Tenancy\Tenancy::company();
        $hidden = (array) ($company?->setting('payout.disabled') ?? []);

        return array_diff_key(Merchant::PAYOUT_METHODS, array_flip($hidden));
    }

    public static function needsDetails(?string $method): bool
    {
        return $method !== null && $method !== 'cash';
    }
}
