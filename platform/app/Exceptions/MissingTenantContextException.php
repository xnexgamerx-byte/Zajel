<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * تُرمى عند الاستعلام عن جدول مقيّد بشركة خارج سياق شركة وخارج سياق النواة.
 *
 * هذا ليس إزعاجاً — هذا هو الفرق بين نظام متعدّد المستأجرين ونظام
 * تُسرَّب فيه شحنات "البرق" إلى لوحة "الزاجل". الاستثناء يقع في التطوير
 * والاختبار قبل أن يقع في الإنتاج.
 */
class MissingTenantContextException extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(
            "استعلام على [{$model}] بلا سياق شركة. "
            . 'استخدم Tenancy::runFor($company, ...) أو Tenancy::runAsPlatform(...) بشكل صريح.'
        );
    }
}
