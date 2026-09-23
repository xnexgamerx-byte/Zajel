<?php

namespace App\Models\Concerns;

/**
 * نصٌّ تبنيه الشيفرة يُقصّ على طول عموده.
 *
 * البيان في الدفتر وحركة الصندوق يُركَّب: «مصروف EX000001 — » ثم وصف
 * المصروف بطوله كلّه، أو اسم التاجر ثم سبب الإيداع. كل جزءٍ منها محدودٌ
 * بطول عموده، ومجموعها ليس محدوداً — فيتجاوز العمود، وتُسقط MySQL الحفظ
 * كلّه («Data too long»): لا يُدفع المصروف ولا يُقيَّد التأمين.
 *
 * البيان وصفٌ للقارئ لا مفتاحٌ يُبحث به، فقصُّه بعلامةٍ ظاهرة («…») أهون
 * من إسقاط العملية المالية كلّها. والأعمدة المقصوصة تُعلَن في النموذج
 * صراحةً: protected array $fits = ['description' => 255];
 */
trait FitsColumns
{
    public static function bootFitsColumns(): void
    {
        static::saving(function ($model) {
            foreach ($model->fits ?? [] as $column => $length) {
                $value = $model->getAttribute($column);

                if (is_string($value) && mb_strlen($value) > $length) {
                    $model->setAttribute($column, rtrim(mb_substr($value, 0, $length - 1)).'…');
                }
            }
        });
    }
}
