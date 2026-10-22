<?php

namespace App\Actions\Couriers;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CourierOutcomeRules;
use App\Enums\ShipmentStatus;
use App\Models\Courier;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ما يسجّله المندوب عند الباب — من شاشته في الموقع أو من تطبيقه (docs/plan/66).
 *
 * مدخلٌ واحد للاثنين، فلا تفترق القواعد: الشحنة بيده و«قيد التوصيل»، والمبلغ لا يتغيّر
 * (الواصل الجزئي بما اعتمده الكول سنتر وحده)، والسبب إلزامي، والكود بمحاولاتٍ محدودة.
 */
class RecordCourierOutcome
{
    /** أربعة انتقالات فقط، وكلّها من «قيد التوصيل» */
    public const ACTIONS = [
        'delivered' => ShipmentStatus::Delivered,
        'partially_delivered' => ShipmentStatus::PartiallyDelivered,
        'failed_attempt' => ShipmentStatus::FailedAttempt,
        'postponed' => ShipmentStatus::Postponed,
    ];

    public function __construct(protected ChangeShipmentStatus $change) {}

    /**
     * @param  array{collected_amount?: ?int, failure_reason_id?: ?int, note?: ?string, delivery_code?: ?string, scan?: ?string, lat?: ?float, lng?: ?float}  $data
     *
     * @throws ValidationException خطأٌ لكل حقل، كما في الشاشة
     */
    public function handle(Courier $courier, User $actor, Shipment $shipment, string $action, array $data): Shipment
    {
        $to = self::ACTIONS[$action] ?? throw ValidationException::withMessages(['action' => 'إجراءٌ غير معروف.']);

        if ((int) $shipment->delivery_courier_id !== $courier->id || $shipment->status !== ShipmentStatus::OutForDelivery) {
            throw ValidationException::withMessages(['action' => 'هذه الشحنة لم تعد بيدك.']);
        }

        // مسح الشحنة قبل أيّ تسجيلٍ عند الباب، إن فُتح للشركة (docs/plan/72)
        if (ScanParcel::required() && ! ScanParcel::matches($shipment, $data['scan'] ?? null)) {
            throw ValidationException::withMessages([
                'scan' => filled($data['scan'] ?? null)
                    ? "ما مسحته ليس الشحنة {$shipment->number}. امسح الطرد الذي في يدك."
                    : "امسح باركود الشحنة {$shipment->number} أو اكتب رقمها قبل التسجيل.",
            ]);
        }

        // الواصل الجزئي بالمبلغ الذي اعتمده الكول سنتر، ولا غيره
        $partial = $to === ShipmentStatus::PartiallyDelivered ? self::approvedPartial($shipment, $courier) : null;

        $errors = CourierOutcomeRules::errors($shipment, $to, $data, $partial);

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $this->change->handle($shipment, $to, $actor, array_filter([
            'actor_type' => 'courier',
            'courier_id' => $courier->id,
            'collected_amount' => $partial?->approved_amount,
            'ticket_id' => $partial?->id,
            'failure_reason_id' => $data['failure_reason_id'] ?? null,
            'note' => $data['note'] ?? null,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /** واصلٌ جزئيّ اعتمده الكول سنتر لهذا المندوب ولم يُسلَّم به بعد */
    public static function approvedPartial(Shipment $shipment, Courier $courier): ?ShipmentTicket
    {
        return ShipmentTicket::query()->where('shipment_id', $shipment->id)->where('courier_id', $courier->id)
            ->awaitingPartial()->latest('id')->first();
    }

    /** الرسالة بعد التسجيل — الاستبدال يُسجَّل «واصل جزئي»: قديمه بيد المندوب (الوثيقة ٣١) */
    public static function message(Shipment $done): string
    {
        return $done->type === 'exchange' && $done->status === ShipmentStatus::PartiallyDelivered
            ? "سُجِّل استبدال الشحنة {$done->number}. سلّم القطعة القديمة للمخزن مع الراجع."
            : "سُجِّلت الشحنة {$done->number}: {$done->status->label()}.";
    }
}
