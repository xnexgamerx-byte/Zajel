<?php

namespace App\Support;

use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;

/**
 * الرسالة الثابتة للتاجر (docs/plan/41): الموظّف لا يكتب الرسالة نفسها كلّ مرّة. نصٌّ واحدٌ له،
 * فيه خاناتٌ بين قوسين تُملأ من الشحنة، فيُنسخ بضغطة أو يُفتح به واتساب التاجر.
 */
final class MerchantMessage
{
    public const MAX = 1000;

    /** القالب الذي يأتي مع النظام، ما لم يكتب الموظّف نصّه */
    public const TEMPLATE = <<<'TXT'
        السلام عليكم {التاجر}،
        بخصوص الوصل {الوصل} للزبون {الزبون} ({الهاتف}) — {العنوان}:
        لم يُسلَّم، والسبب: {السبب}.
        نرجو إعلامنا بالإجراء: إعادة توصيل، أو تأجيل لموعدٍ يحدّده الزبون، أو إرجاعه لكم.
        مع التحية، {الموظف} — {الشركة}
        TXT;

    /** الخانات وما تُملأ به — تُعرض للموظّف كما هي */
    public const FIELDS = [
        '{التاجر}'  => 'اسم التاجر',
        '{الوصل}'   => 'رقم الوصل',
        '{الزبون}'  => 'اسم الزبون',
        '{الهاتف}'  => 'هاتف الزبون',
        '{العنوان}' => 'المحافظة والمنطقة',
        '{المبلغ}'  => 'المبلغ',
        '{السبب}'   => 'سبب عدم التسليم',
        '{المندوب}' => 'اسم المندوب',
        '{الموظف}'  => 'اسمك',
        '{الشركة}'  => 'اسم الشركة',
    ];

    public static function templateOf(User $user): string
    {
        $own = trim((string) $user->merchant_message);

        return $own !== '' ? $own : self::TEMPLATE;
    }

    /** نصّ الموظّف مملوءاً من الشحنة */
    public static function for(Shipment $shipment, User $user): string
    {
        $address = trim(($shipment->governorate?->name_ar ?? '').' · '.($shipment->city?->name_ar ?? ''), ' ·');

        return strtr(self::templateOf($user), [
            '{التاجر}'  => (string) $shipment->merchant?->business_name,
            '{الوصل}'   => (string) $shipment->number,
            '{الزبون}'  => (string) ($shipment->recipient_name ?: 'الزبون'),
            '{الهاتف}'  => (string) $shipment->recipient_phone,
            '{العنوان}' => $address !== '' ? $address : '—',
            '{المبلغ}'  => number_format((int) $shipment->cod_amount).' د.ع',
            '{السبب}'   => $shipment->lastFailureReason?->name_ar ?? 'لم يُحدَّد',
            '{المندوب}' => (string) ($shipment->deliveryCourier?->name ?? '—'),
            '{الموظف}'  => (string) $user->name,
            '{الشركة}'  => (string) (Tenancy::company()?->name ?? ''),
        ]);
    }
}
