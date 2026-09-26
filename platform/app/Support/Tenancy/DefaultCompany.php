<?php

namespace App\Support\Tenancy;

/**
 * نظامٌ بلا نطاق (zajel.default_company): كل عنوانٍ ليس نطاقاً فرعياً للنطاق
 * الأساسي — عنوان Railway المجاني مثلاً — يفتح هذه الشركة وحدها.
 */
final class DefaultCompany
{
    /** النطاق الفرعي للشركة الافتراضية، أو '' إن لم تُضبَط. */
    public static function slug(): string
    {
        return (string) config('zajel.default_company');
    }

    /** هل هذا العنوان خارج النطاقات الفرعية، فهو للشركة الافتراضية؟ */
    public static function covers(string $host): bool
    {
        $base = (string) config('zajel.tenant_domain');

        return $base === '' || ! str_ends_with($host, '.'.$base);
    }
}
