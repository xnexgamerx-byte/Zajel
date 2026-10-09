<?php

namespace App\Support;

use App\Models\Company;
use App\Support\Tenancy\Tenancy;

/**
 * الحقول التي تختار الشركة إلزامها عند إدخال الشحنة (docs/plan/38).
 *
 * ما لا تخرج شحنةٌ بغيره ملزَمٌ دائماً: الهاتف والمحافظة والمنطقة (حيث لها مناطق)
 * والمبلغ. وما سواه اختياريٌّ أصلاً — واسم المستلم منه: يُطبع على الوصل إن كُتب —
 * وتُلزِم الشركة منه ما تشاء من «بيانات الشركة»، فيسري على كل نموذج: نموذج
 * الموظّف، وبوابة التاجر، والإدخال السريع، وملفّ Excel.
 */
final class ShipmentFields
{
    public const CHOOSABLE = [
        'recipient_name'      => 'اسم المستلم',
        'recipient_phone_alt' => 'الهاتف البديل',
        'landmark'            => 'أقرب نقطة دالّة',
        'description'         => 'وصف المحتوى (نوع البضاعة)',
        'merchant_reference'  => 'رقم طلب التاجر',
        'notes'               => 'ملاحظات للمندوب',
    ];

    /** @return list<string> ما ألزمته الشركة من CHOOSABLE */
    public static function required(?Company $company = null): array
    {
        $company ??= Tenancy::company();
        $chosen = (array) ($company?->setting('shipment.required', []) ?? []);

        return array_values(array_intersect(array_keys(self::CHOOSABLE), $chosen));
    }

    public static function isRequired(string $field, ?Company $company = null): bool
    {
        return in_array($field, self::required($company), true);
    }

    /** «اكتب اسم المستلم: الشركة تُلزِم به.» */
    public static function message(string $field): string
    {
        return 'اكتب '.(self::CHOOSABLE[$field] ?? $field).': الشركة تُلزِم به.';
    }
}
