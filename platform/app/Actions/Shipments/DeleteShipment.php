<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Models\AuditLog;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * مسح الشحنة واسترجاعها («شحنات ممسوحة» في المعتاد).
 *
 * تُمسح الشحنة التي أُنشئت خطأً قبل أن تصلنا: ما دامت عند تاجرها أو أُلغيت،
 * ولا مال لها في الدفتر ولا كيس يحملها. وما وصل المخزن طردٌ حقيقيّ بيدنا:
 * يُلغى أو يُرجع، لا يختفي. والمسح ليس حذفاً — تبقى في السلّة بمن مسحها ومتى
 * ولماذا، وتُسترجع كما كانت.
 */
class DeleteShipment
{
    /** @var list<ShipmentStatus> */
    public const DELETABLE = [ShipmentStatus::Created, ShipmentStatus::PendingPickup, ShipmentStatus::Cancelled];

    public static function deletable(Shipment $shipment): bool
    {
        return in_array($shipment->status, self::DELETABLE, true)
            && $shipment->merchant_settled_at === null
            && $shipment->current_bag_id === null;
    }

    public function delete(Shipment $shipment, string $reason, User $actor): void
    {
        DB::transaction(function () use ($shipment, $reason, $actor) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            if (! static::deletable($shipment)) {
                throw ValidationException::withMessages([
                    'reason' => "الشحنة {$shipment->number} «{$shipment->status->label()}»: وصلتنا فلا تُمسح — أُلغِها أو أرجعها.",
                ]);
            }

            $shipment->forceFill([
                'deleted_by_user_id' => $actor->id,
                'deleted_by_name'    => $actor->name,
                'delete_reason'      => $reason,
            ])->save();

            $this->log($shipment, 'deleted', 'مُسحت — '.$reason, $actor);
            $this->audit('shipment_deleted', $shipment, ['number' => $shipment->number, 'reason' => $reason], $actor);

            $shipment->delete();
        });
    }

    public function restore(Shipment $shipment, User $actor): void
    {
        DB::transaction(function () use ($shipment, $actor) {
            $shipment->restore();
            $shipment->forceFill(['deleted_by_user_id' => null, 'deleted_by_name' => null, 'delete_reason' => null])->save();

            $this->log($shipment, 'restored', 'استُرجعت من الشحنات الممسوحة', $actor);
            $this->audit('shipment_restored', $shipment, ['number' => $shipment->number], $actor);
        });
    }

    protected function log(Shipment $shipment, string $type, string $note, User $actor): void
    {
        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'from_status' => $shipment->status->value,
            'to_status'   => $shipment->status->value,
            'event_type'  => $type,
            'actor_type'  => 'user',
            'actor_id'    => $actor->id,
            'actor_name'  => $actor->name,
            'note'        => $note,
            'ip'          => request()->ip(),
        ]);
    }

    protected function audit(string $action, Shipment $shipment, array $values, User $actor): void
    {
        AuditLog::create([
            'user_id'        => $actor->id,
            'user_name'      => $actor->name,
            'action'         => $action,
            'auditable_type' => Shipment::class,
            'auditable_id'   => $shipment->id,
            'new_values'     => $values,
            'ip'             => request()->ip(),
        ]);
    }
}
