<?php

namespace App\Actions\Returns;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Merchant;
use App\Models\Shipment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تسليم الراجع للتاجر.
 *
 * هنا تصير الشحنة «راجعة» فعلاً، وعندها تُقيَّد أجرة الإرجاع على التاجر
 * وعمولة الإرجاع للمندوب. لا يُسلَّم طرد لم يُستلم من المندوب أصلاً:
 * توقيع التاجر على ما ليس في يدك هو الخلاف نفسه الذي نتجنّبه.
 */
class HandOverReturns
{
    public function __construct(protected ChangeShipmentStatus $changeStatus) {}

    /** @return Collection<int, Shipment> */
    public function handle(array $shipmentIds, Merchant $merchant, ?\App\Models\User $actor = null, ?string $note = null): Collection
    {
        if ($shipmentIds === []) {
            return collect();
        }

        return DB::transaction(function () use ($shipmentIds, $merchant, $actor, $note) {
            $shipments = Shipment::query()
                ->whereIn('id', $shipmentIds)
                ->where('merchant_id', $merchant->id)
                ->where('status', ShipmentStatus::Returning->value)
                ->lockForUpdate()
                ->get();

            $unreceived = $shipments->whereNull('return_received_at');

            if ($unreceived->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'shipments' => 'لم تُستلم هذه الشحنات من المندوب بعد: '
                        .$unreceived->pluck('number')->implode('، '),
                ]);
            }

            return $shipments->map(fn (Shipment $shipment) => $this->changeStatus->handle(
                $shipment,
                ShipmentStatus::Returned,
                $actor,
                ['note' => $note ?: 'سُلّم الراجع للتاجر'],
            ));
        });
    }

    /** ما وصل المخزن وينتظر التاجر. */
    public function ready(?int $merchantId = null): Collection
    {
        return Shipment::query()
            ->with(['merchant:id,business_name,code', 'deliveryCourier:id,name', 'lastFailureReason:id,name_ar'])
            ->where('status', ShipmentStatus::Returning->value)
            ->whereNotNull('return_received_at')
            ->when($merchantId, fn ($q) => $q->where('merchant_id', $merchantId))
            ->orderBy('return_received_at')
            ->get();
    }
}
