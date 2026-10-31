<?php

namespace App\Support;

use App\Models\Company;

/**
 * تصميم نظام الشركة (docs/plan/100): شكل شاشات موظّفيها — الأسطح والقوائم والأزرار — لا لونها.
 * يختاره صاحب المنصّة لكل شركة، ولونها من «المظهر» (Theme) يجري في كلّ تصميم.
 *
 * التصميم سمةٌ على <html data-ui="…">، وما يتغيّر به في resources/css/ui-designs.css وحده:
 * الشاشات نفسها، بلا نسخةٍ لكل تصميم.
 */
final class UiDesign
{
    public const DEFAULT = 'nabd';

    /** بترتيب العرض في لوحة المنصّة: [الاسم، الوصف] */
    public const DESIGNS = [
        'nabd' => ['نبض (الحالي)', 'شريطٌ جانبيّ بأيقونات، وأرضيةٌ بأفتح درجات لون الشركة، وبطاقاتٌ بيضاء.'],
        'soft' => ['بطاقات فاتحة', 'قوائم في شريطٍ علويّ كالحبّة، والحاليّة داكنة. أرضيةٌ رماديةٌ فاتحة، وبطاقاتٌ بيضاء واسعة الزوايا.'],
        'glass' => ['زجاجيّ', 'أرضيةٌ خضراء رمادية هادئة، وبطاقاتٌ زجاجية شفّافة، وقوائم في شريطٍ علويّ، والحاليّ والفعل الأوّل بالليمونيّ.'],
    ];

    private function __construct(public readonly string $key) {}

    public static function for(Company $company): self
    {
        return self::make((string) $company->setting('ui', self::DEFAULT));
    }

    /** التصميم بمفتاحه، والمجهول «نبض» */
    public static function make(string $key): self
    {
        return new self(isset(self::DESIGNS[$key]) ? $key : self::DEFAULT);
    }

    public function name(): string
    {
        return self::DESIGNS[$this->key][0];
    }

    public function hint(): string
    {
        return self::DESIGNS[$this->key][1];
    }

    /** القوائم في شريطٍ علويّ لا في الجانب */
    public function topNav(): bool
    {
        return $this->key !== self::DEFAULT;
    }
}
