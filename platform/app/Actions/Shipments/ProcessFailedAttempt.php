<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * قرار «المعالجة» في محاولةٍ فاشلة: إعادة توصيل، أو تأجيلٌ إلى موعد، أو إرجاعٌ
 * للتاجر — من موظّف المتابعة، أو من التاجر نفسه إن سُمح له («إدخال طلبات
 * العميل للمعالجة» في المعتاد). طريقٌ واحد للاثنين، ويُكتب في سجلّ الشحنة
 * من قرّر وبعد كم انتظرت؛ ومنه تُقرأ «موظّفو المتابعة» و«أداء المراجعة».
 */
class ProcessFailedAttempt
{
    public const ACTIONS = [
        'redeliver' => 'إعادة توصيل',
        'postpone'  => 'تأجيل',
        'return'    => 'إرجاع للتاجر',
    ];

    public function __construct(protected ChangeShipmentStatus $change) {}

    /** @param 'staff'|'merchant' $by */
    public function handle(
        Shipment $shipment,
        string $action,
        User $actor,
        string $by = 'staff',
        ?string $until = null,
        ?string $said = null,
        ?string $ip = null,
    ): ShipmentStatus {
        return DB::transaction(function () use ($shipment, $action, $actor, $by, $until, $said, $ip) {
            // الحال بعد القفل: قراران متزامنان (موظّفٌ وتاجر) لا يمرّان معاً
            $shipment->setRawAttributes(Shipment::query()->lockForUpdate()->findOrFail($shipment->id)->getAttributes(), true);

            if ($shipment->status !== ShipmentStatus::FailedAttempt) {
                throw ValidationException::withMessages([
                    'action' => "الشحنة {$shipment->number} «{$shipment->status->label()}»: عولجت سلفاً.",
                ]);
            }

            $waited = (int) $shipment->status_changed_at?->diffInMinutes(now());
            $note = ($by === 'merchant' ? 'معالجة من التاجر' : 'معالجة').(filled($said) ? ' — '.$said : '');
            $options = ['note' => $note] + ($by === 'merchant' ? ['actor_type' => 'merchant'] : []);

            [$to, $options] = match ($action) {
                // مع مندوبها نفسه إن كان لها مندوب، وإلّا إلى المخزن تنتظر الإسناد
                'redeliver' => $shipment->delivery_courier_id
                    ? [ShipmentStatus::OutForDelivery, $options + ['courier_id' => $shipment->delivery_courier_id]]
                    : [ShipmentStatus::AtHub, $options],
                'postpone'  => [ShipmentStatus::Postponed, $options + ['scheduled_at' => Carbon::parse($until)->startOfDay()]],
                'return'    => [ShipmentStatus::Returning, $options],
            };

            $this->change->handle($shipment, $to, $actor, $options);

            ShipmentEvent::create([
                'shipment_id' => $shipment->id,
                'from_status' => ShipmentStatus::FailedAttempt->value,
                'to_status'   => $to->value,
                'event_type'  => 'processed',
                'actor_type'  => $by === 'merchant' ? 'merchant' : 'user',
                'actor_id'    => $actor->id,
                'actor_name'  => $actor->name,
                'courier_id'  => $shipment->delivery_courier_id,
                'note'        => $note,
                'meta'        => ['action' => $action, 'waited_minutes' => $waited, 'by' => $by, 'said' => $said],
                'ip'          => $ip,
            ]);

            return $to;
        });
    }
}
