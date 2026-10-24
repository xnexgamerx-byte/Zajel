<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Http\Requests\ChangeStatusRequest;
use App\Models\Shipment;
use App\Models\User;

/**
 * «تحديث الحالة» لشحنةٍ واحدة بيد موظّف — من صفحتها في الموقع، ومن تطبيق الكول سنتر (docs/plan/80).
 * طريقٌ واحد للاثنين بعد ChangeStatusRequest نفسه، فلا يفترق ما يُسمح به هنا عمّا يُسمح به هناك.
 */
class StaffStatusUpdate
{
    public function __construct(
        protected ChangeShipmentStatus $change,
        protected ProcessFailedAttempt $process,
    ) {}

    /**
     * @param  array<string, mixed>  $data  ما مرّ من ChangeStatusRequest
     * @return string ما يُقال للموظّف بعده
     */
    public function handle(Shipment $shipment, array $data, User $user, ?string $ip = null): string
    {
        $note = $data['note'] ?? null;

        // «إعادة توصيل» و«واصل إجباري» من القائمة نفسها (docs/plan/53)
        if ($data['status'] === ChangeStatusRequest::REDELIVER) {
            if ($shipment->status === ShipmentStatus::FailedAttempt) {
                // كما في «شحنات لم تُسلَّم»: تُسجَّل معالجةً باسمه
                $this->process->handle($shipment, 'redeliver', $user, 'staff', null, $note, $ip);
            } else {
                $this->change->handle($shipment, ShipmentStatus::OutForDelivery, $user, array_filter([
                    'courier_id' => ($data['courier_id'] ?? null) ?: $shipment->delivery_courier_id,
                    'redelivery' => true,
                    'note' => $note ?: 'إعادة توصيل',
                ], fn ($v) => $v !== null));
            }

            return "أُعيدت الشحنة {$shipment->number} للتوصيل.";
        }

        if ($data['status'] === ChangeStatusRequest::FORCE_DELIVERED) {
            $this->change->handle($shipment, ShipmentStatus::Delivered, $user, array_filter([
                'force' => true,
                'forced_reason' => $data['force_delivered_reason'] ?? null,
                'collected_amount' => (int) ($data['collected_amount'] ?? 0),
                'note' => $note,
            ], fn ($v) => $v !== null));

            return "سُجّلت الشحنة {$shipment->number} «واصل» إجبارياً، وسُجّل السبب باسمك.";
        }

        $to = ShipmentStatus::from($data['status']);
        $force = ! empty($data['force']);

        $this->change->handle($shipment, $to, $user, array_filter([
            'courier_id' => $data['courier_id'] ?? null,
            'hub_id' => $data['hub_id'] ?? null,
            'failure_reason_id' => $data['failure_reason_id'] ?? null,
            'note' => $note,
            'collected_amount' => $data['collected_amount'] ?? null,
            'force' => $force ?: null,
            'retry' => ! empty($data['retry']) ?: null,
            'forced_reason' => $force ? ($data['forced_reason'] ?? null) : null,
        ], fn ($v) => $v !== null));

        return $force
            ? "تم تحديث الشحنة {$shipment->number} إلى «{$to->label()}» إجبارياً، وسُجّل السبب باسمك."
            : "تم تحديث الشحنة {$shipment->number} إلى «{$to->label()}».";
    }
}
