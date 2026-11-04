<?php

namespace App\Support;

use App\Enums\Feature;
use App\Models\Company;
use App\Support\Tenancy\Tenancy;

/**
 * نصوص الإشعارات التي تصل الهواتف (docs/plan/91): لكل إشعارٍ عنوانٌ ونصّ تكتبهما الشركة بنفسها، فيهما
 * خاناتٌ بين قوسين تُملأ ممّا حدث — كالرسالة الثابتة للتاجر (MerchantMessage).
 *
 * ما لم تكتبه الشركة يبقى نصّ النظام كما كان، حرفاً بحرف: الكود يبني نصّه، وهذا يستبدله بنصّ الشركة
 * إن كان لها نصّ والميزة مفتوحةٌ لها. ويُحفظ في companies.settings تحت notify_texts.
 */
final class NotifyTexts
{
    public const MAX_TITLE = 120;

    public const MAX_BODY = 400;

    public const AUDIENCES = [
        'merchant' => 'التاجر',
        'courier' => 'المندوب',
        'callcenter' => 'الكول سنتر',
    ];

    /**
     * كل إشعارٍ: لمن يصل، ومتى، ونصّ النظام بخاناته، والخانات وما تُملأ به.
     *
     * @return array<string, array{audience: string, when: string, title: string, body: string, fields: array<string, string>}>
     */
    public static function catalog(): array
    {
        $shipment = ['{الوصل}' => 'رقم الوصل', '{الزبون}' => 'اسم الزبون'];

        return [
            'amount_change' => [
                'audience' => 'merchant',
                'when' => 'غيّرت الشركة مبلغ وصل',
                'title' => 'تغيّر مبلغ الوصل {الوصل}',
                'body' => 'المبلغ صار {المبلغ الجديد} بدل {المبلغ السابق} — زبونك {الزبون}. وتحدّث في وصلك وحسابك.',
                'fields' => $shipment + ['{المبلغ الجديد}' => 'المبلغ بعد التغيير', '{المبلغ السابق}' => 'المبلغ قبله'],
            ],
            'delivered' => [
                'audience' => 'merchant',
                'when' => 'سُلّمت شحنته',
                'title' => 'وصلت شحنتك {الوصل}',
                'body' => 'سُلّمت وقُبض {المقبوض} — زبونك {الزبون}.',
                'fields' => $shipment + ['{المقبوض}' => 'ما قُبض من الزبون'],
            ],
            'delivered_partial' => [
                'audience' => 'merchant',
                'when' => 'سُلّم بعض شحنته',
                'title' => 'وصلت شحنتك {الوصل} جزئياً',
                'body' => 'سُلّم بعضها وقُبض {المقبوض} من {المبلغ}، والباقي يرجع إليك — زبونك {الزبون}.',
                'fields' => $shipment + ['{المقبوض}' => 'ما قُبض من الزبون', '{المبلغ}' => 'مبلغ الوصل'],
            ],
            'failed' => [
                'audience' => 'merchant',
                'when' => 'لم تُسلَّم شحنته',
                'title' => 'شحنتك {الوصل} لم تُسلَّم — للمعالجة',
                'body' => 'السبب: {السبب}. نتّصل بالزبون ونخبرك بالقرار — زبونك {الزبون}.',
                'fields' => $shipment + ['{السبب}' => 'سبب عدم التسليم'],
            ],
            'processed_redeliver' => [
                'audience' => 'merchant',
                'when' => 'قرّر الكول سنتر إعادة توصيل شحنته',
                'title' => 'إعادة توصيل شحنتك {الوصل}',
                'body' => 'اتّصلنا بالزبون، وتخرج إليه ثانيةً — زبونك {الزبون}.',
                'fields' => $shipment,
            ],
            'processed_postpone' => [
                'audience' => 'merchant',
                'when' => 'أجّل الكول سنتر شحنته إلى موعد',
                'title' => 'أُجّلت شحنتك {الوصل}',
                'body' => 'اتّفقنا مع الزبون على التسليم {الموعد} — زبونك {الزبون}.',
                'fields' => $shipment + ['{الموعد}' => 'الموعد بكلام الناس: «غداً الساعة 9:00 م»'],
            ],
            'processed_return' => [
                'audience' => 'merchant',
                'when' => 'تأكّد أنّ شحنته راجعة',
                'title' => 'شحنتك {الوصل} راجعة إليك',
                'body' => 'تأكّد أنّ الزبون لا يستلمها، فترجع إليك مع الراجع — زبونك {الزبون}.',
                'fields' => $shipment,
            ],
            'courier_assigned_one' => [
                'audience' => 'courier',
                'when' => 'أُسندت إليه شحنةٌ واحدة للتوصيل',
                'title' => 'شحنات جديدة للتوصيل',
                'body' => 'أُسندت إليك الشحنة {الوصل} — افتح التطبيق لتراها.',
                'fields' => ['{الوصل}' => 'رقم الوصل'],
            ],
            'courier_assigned_many' => [
                'audience' => 'courier',
                'when' => 'أُسندت إليه شحناتٌ للتوصيل',
                'title' => 'شحنات جديدة للتوصيل',
                'body' => 'أُسندت إليك {الشحنات} للتوصيل — افتح التطبيق لتراها.',
                'fields' => ['{الشحنات}' => 'عددها بكلامه: «شحنتان»، «٥ شحنات»'],
            ],
            'courier_message' => [
                'audience' => 'courier',
                'when' => 'كتب له الكول سنتر',
                'title' => 'رسالة من الكول سنتر',
                'body' => '{الرسالة}',
                'fields' => ['{الرسالة}' => 'أوّل الرسالة'],
            ],
            'callcenter_ticket' => [
                'audience' => 'callcenter',
                'when' => 'فتح مندوبٌ تذكرة',
                'title' => 'تذكرة جديدة من مندوب',
                'body' => 'شحنة {الوصل}: {الطلب} — {التذكرة}. افتح التذكرة لتقرّر.',
                'fields' => ['{الوصل}' => 'رقم الوصل', '{الطلب}' => 'ما يطلبه المندوب', '{التذكرة}' => 'رقم التذكرة', '{المندوب}' => 'اسم المندوب'],
            ],
            'callcenter_courier_message' => [
                'audience' => 'callcenter',
                'when' => 'كتب مندوب',
                'title' => 'رسالة من مندوب: {المندوب}',
                'body' => '{الرسالة}',
                'fields' => ['{المندوب}' => 'اسم المندوب', '{الرسالة}' => 'أوّل الرسالة'],
            ],
            'callcenter_merchant_message' => [
                'audience' => 'callcenter',
                'when' => 'كتب تاجر',
                'title' => 'رسالة من تاجر: {المتجر}',
                'body' => '{الرسالة}',
                'fields' => ['{المتجر}' => 'اسم المتجر', '{الرسالة}' => 'أوّل الرسالة'],
            ],
        ];
    }

    /** نصّ الشركة لإشعارٍ، إن كتبته: ['title' => …, 'body' => …] أو بعضهما */
    public static function stored(Company $company, string $key): array
    {
        $own = $company->setting("notify_texts.{$key}");

        return is_array($own) ? array_filter(array_intersect_key($own, ['title' => 1, 'body' => 1]), fn ($v) => is_string($v) && trim($v) !== '') : [];
    }

    /**
     * [العنوان, النصّ] كما يصل الهاتف: نصّ الشركة مملوءاً بـ$values، وإلّا ما بناه الكود. والخانة الفارغة
     * بعد الملء تُعيد نصّ النظام لا إشعاراً بلا عنوان.
     *
     * @param  array<string, string|int>  $values  ['{الوصل}' => …]
     * @return array{0: string, 1: string}
     */
    public static function apply(string $key, string $title, string $body, array $values, ?Company $company = null): array
    {
        $company ??= Tenancy::company();

        if (! $company || ! $company->hasFeature(Feature::NotifyTexts)) {
            return [$title, $body];
        }

        $own = self::stored($company, $key);
        $values = array_map('strval', $values);
        $fill = fn (string $text, string $default) => ($text = trim(strtr($text, $values))) !== '' ? $text : $default;

        return [
            isset($own['title']) ? $fill($own['title'], $title) : $title,
            isset($own['body']) ? $fill($own['body'], $body) : $body,
        ];
    }

    /** الخانات في نصٍّ وليست من خانات إشعاره — خطأٌ في الكتابة يُرَدّ لا يصل هاتفاً بقوسيه */
    public static function unknownFields(string $key, string $text): array
    {
        preg_match_all('/\{[^{}]*\}/u', $text, $found);

        return array_values(array_diff(array_unique($found[0]), array_keys(self::catalog()[$key]['fields'] ?? [])));
    }

    /** يُحفظ ما اختلف عن نصّ النظام وحده: الفارغ وما طابقه يعود يتبع النظام */
    public static function normalise(string $key, ?string $text, string $part): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        return $text === '' || $text === self::catalog()[$key][$part] ? null : $text;
    }
}
