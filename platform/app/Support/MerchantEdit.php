<?php

namespace App\Support;

use App\Actions\Shipments\UpdateShipment;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Push\StaffPush;
use Illuminate\Support\Facades\DB;

/**
 * تعديل التاجر لشحنته (docs/plan/113)، من بوّابته وتطبيقه، خلف الميزة merchant_edit.
 *
 * - قبل أن يستلمها المندوب (جديدة، أو بانتظار الاستلام): كلّ ما في نموذج إنشائه — الزبون وهاتفاه والمحافظة
 *   والمنطقة والعنوان والسعر والعدد والنوع والحجم والملاحظات. والأجرة تُعاد من التسعيرة إن تغيّرت الوجهة أو
 *   الحجم، لا من يده.
 * - بعد الاستلام وما دامت في الطريق: اسم الزبون وهاتفاه وعنوانه وأقرب نقطة والملاحظات وحدها. الطرد مع المندوب
 *   والسعر على وصله: تغيير السعر أو الوجهة بعدها من الكول سنتر. ويصل المندوبَ إشعارٌ بما تغيّر.
 * - ولا تعديل لشحنةٍ حُسمت (سُلّمت أو رجعت أو أُلغيت) أو راجعةٍ إليه، ولا لشحنةٍ عند الشركة الوسيطة بعد الاستلام:
 *   بياناتها في نظامهم ولا تتحدّث منّا.
 *
 * والتعديل نفسه UpdateShipment: القفل وإعادة الفحص، وحدث «تعديل البيانات» في السجلّ بما كان وما صار ومن غيّره.
 */
final class MerchantEdit
{
    /** ما يُعدَّل بعد الاستلام: ما يوصل الطرد إلى زبونه */
    public const CONTACT = ['recipient_name', 'recipient_phone', 'recipient_phone_alt', 'address', 'landmark', 'notes'];

    /** وما يُعدَّل قبله: نموذج إنشائه كلّه */
    public const FULL = [...self::CONTACT, 'governorate_id', 'city_id', 'description', 'pieces_count', 'type', 'size', 'cod_amount'];

    /** في الطريق بعد الاستلام: يصلها تعديل بيانات الزبون */
    private const ON_THE_WAY = [
        ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery,
        ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed,
    ];

    /** مع مندوب التوصيل الآن: يُبلَّغ بما تغيّر */
    private const WITH_COURIER = [ShipmentStatus::OutForDelivery, ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed];

    public static function enabled(): bool
    {
        return FeatureGate::enabled(Feature::MerchantEdit);
    }

    /** «full» قبل الاستلام، و«contact» بعده، وnull حين لا تُعدَّل */
    public static function mode(Shipment $shipment): ?string
    {
        return self::locked($shipment) === null
            ? (in_array($shipment->status->value, ShipmentStatus::enteredValues(), true) ? 'full' : 'contact')
            : null;
    }

    /** لماذا لا يعدّلها، بكلام التاجر — وnull إن كانت تُعدَّل */
    public static function locked(Shipment $shipment): ?string
    {
        if (in_array($shipment->status->value, ShipmentStatus::enteredValues(), true)) {
            return null;
        }

        return match (true) {
            ! UpdateShipment::editable($shipment) => 'لا تُعدَّل شحنةٌ بعد تسليمها أو إرجاعها أو إلغائها.',
            ! in_array($shipment->status, self::ON_THE_WAY, true) => 'الشحنة راجعةٌ إليك: لا تُعدَّل بياناتها.',
            (bool) $shipment->deliveryCourier?->is_intermediary => 'الشحنة عند شريك التوصيل: لتغيير بياناتها راسل الدعم.',
            default => null,
        };
    }

    /** للتطبيق والبوابة: أيُعدَّل، وما يُعدَّل، ولماذا لا */
    public static function describe(Shipment $shipment): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        $mode = self::mode($shipment);

        return [
            'mode' => $mode,
            'fields' => match ($mode) {
                'full' => self::FULL,
                'contact' => self::CONTACT,
                default => [],
            },
            'locked' => $mode === null ? self::locked($shipment) : null,
        ];
    }

    /**
     * يحفظ ما عدّله التاجر. $input ما تحقّق منه نموذجه؛ وما ليس من حقول وضعه يبقى كما هو ولو أُرسل.
     */
    public static function apply(Shipment $shipment, array $input, User $actor): Shipment
    {
        $mode = self::mode($shipment) ?? 'contact';
        $fields = $mode === 'full' ? self::FULL : self::CONTACT;

        // الشحنة كما هي، وفوقها ما عدّله. والأجرة بلا رقم: تبقى، أو تُعاد من التسعيرة إن تغيّرت الوجهة
        $data = UpdateShipment::withAmount($shipment, (int) $shipment->cod_amount);
        unset($data['delivery_fee']);

        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field];
            }
        }

        // محافظةٌ بلا مناطق لا يبقى لها منطقة المحافظة السابقة
        if ($mode === 'full') {
            $data['city_id'] = $input['city_id'] ?? null;
        }

        $before = $shipment->only(self::CONTACT);
        $updated = app(UpdateShipment::class)->handle($shipment, $data, $actor, 'عدّلها التاجر');

        $changed = array_values(array_filter(self::CONTACT, fn ($f) => (string) ($before[$f] ?? '') !== (string) ($updated->{$f} ?? '')));

        if ($changed !== [] && $updated->delivery_courier_id && in_array($updated->status, self::WITH_COURIER, true)) {
            $courier = $updated->deliveryCourier()->first();
            $what = implode('، ', array_map(fn ($f) => UpdateShipment::FIELDS[$f], $changed));

            if ($courier && ! $courier->is_intermediary) {
                DB::afterCommit(fn () => StaffPush::courier($courier, ...NotifyTexts::apply(
                    'courier_edited',
                    "تعديل على الشحنة {$updated->number}",
                    "عدّل التاجر {$what} — افتح الشحنة قبل أن تتّصل بالزبون.",
                    ['{الوصل}' => $updated->number, '{ما تغيّر}' => $what],
                ), kind: 'edited'));
            }
        }

        return $updated;
    }
}
