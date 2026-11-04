<?php

namespace App\Support;

use App\Actions\Shipments\DeleteShipment;
use App\Actions\Shipments\UpdateShipment;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\User;

/**
 * تعديل التاجر لشحنته (docs/plan/113)، من بوّابته وتطبيقه أينما فتحها، خلف الميزة merchant_edit.
 *
 * - ما دامت عنده — جديدة، أو بانتظار الاستلام: كلّ ما في نموذج إنشائه — الزبون وهاتفاه والمحافظة والمنطقة والعنوان
 *   والسعر والعدد والنوع والحجم والملاحظات. والأجرة تُعاد من التسعيرة إن تغيّرت الوجهة أو الحجم، لا من يده.
 * - فإذا استلمها مندوب الاستلام لا يعدّل فيها شيئاً (قرار الشركة، docs/plan/118): الطرد بيدها، وما يتغيّر بعدها
 *   يتغيّر من الكول سنتر.
 *
 * والتعديل نفسه UpdateShipment: القفل وإعادة الفحص، وحدث «تعديل البيانات» في السجلّ بما كان وما صار ومن غيّره.
 */
final class MerchantEdit
{
    /** ما يعدّله: نموذج إنشائه كلّه */
    public const FULL = [
        'recipient_name', 'recipient_phone', 'recipient_phone_alt', 'address', 'landmark', 'notes',
        'governorate_id', 'city_id', 'description', 'pieces_count', 'type', 'size', 'cod_amount',
    ];

    public static function enabled(): bool
    {
        return FeatureGate::enabled(Feature::MerchantEdit);
    }

    /** «full» ما دامت عنده، وnull حين لا تُعدَّل */
    public static function mode(Shipment $shipment): ?string
    {
        return self::locked($shipment) === null ? 'full' : null;
    }

    /** لماذا لا يعدّلها، بكلام التاجر — وnull إن كانت تُعدَّل */
    public static function locked(Shipment $shipment): ?string
    {
        return match (true) {
            in_array($shipment->status->value, ShipmentStatus::enteredValues(), true) => null,
            ! UpdateShipment::editable($shipment) => 'لا تُعدَّل شحنةٌ بعد تسليمها أو إرجاعها أو إلغائها.',
            default => 'استلمها المندوب: لا تُعدَّل بعد الاستلام. لتغيير شيءٍ فيها راسل الدعم.',
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
            'fields' => $mode === null ? [] : self::FULL,
            'locked' => $mode === null ? self::locked($shipment) : null,
            // «احذف الشحنة» في التعديل (docs/plan/117): ما دامت لم تصلنا — قواعد المسح نفسها
            'deletable' => DeleteShipment::deletable($shipment),
        ];
    }

    /**
     * يحذف التاجر شحنته (docs/plan/117): المسح نفسه الذي يمسح به الموظّف — ما دامت جديدةً أو بانتظار الاستلام أو
     * أُلغيت، ولا كيس يحملها ولا كشف — فتذهب إلى «الشحنات الممسوحة» بمن مسحها ولماذا، وتُسترجع منها.
     */
    public static function delete(Shipment $shipment, ?string $reason, User $actor): void
    {
        app(DeleteShipment::class)->delete($shipment, 'حذفها التاجر'.(filled($reason) ? ' — '.trim($reason) : ''), $actor);
    }

    /**
     * يحفظ ما عدّله التاجر. $input ما تحقّق منه نموذجه؛ وما ليس من حقول وضعه يبقى كما هو ولو أُرسل.
     */
    public static function apply(Shipment $shipment, array $input, User $actor): Shipment
    {
        // الشحنة كما هي، وفوقها ما عدّله. والأجرة بلا رقم: تبقى، أو تُعاد من التسعيرة إن تغيّرت الوجهة
        $data = UpdateShipment::withAmount($shipment, (int) $shipment->cod_amount);
        unset($data['delivery_fee']);

        foreach (self::FULL as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field];
            }
        }

        // محافظةٌ بلا مناطق لا يبقى لها منطقة المحافظة السابقة
        $data['city_id'] = $input['city_id'] ?? null;

        return app(UpdateShipment::class)->handle($shipment, $data, $actor, 'عدّلها التاجر');
    }
}
