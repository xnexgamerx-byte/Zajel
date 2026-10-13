<?php

namespace App\Actions\Partners;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Partner;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * نسلّم الطرود للشريك (docs/plan/64): من «بالمخزن» إلى «بالطريق» مع الشريك، ويُعلَّم عليه.
 * كل طرد في معاملته، فطردٌ مرفوض لا يوقف الباقي.
 */
class HandOverToPartner
{
    public function __construct(protected ChangeShipmentStatus $change) {}

    /**
     * @param  list<int>  $shipmentIds
     * @return array{moved: list<string>, skipped: array<string, string>}
     */
    public function handle(Partner $partner, array $shipmentIds, User $actor): array
    {
        $result = ['moved' => [], 'skipped' => []];

        if (! $partner->isActive()) {
            throw ValidationException::withMessages(['partner' => 'هذا الشريك موقوف.']);
        }

        foreach (Shipment::query()->whereIn('id', $shipmentIds)->orderBy('id')->get() as $shipment) {
            try {
                DB::transaction(function () use ($partner, $shipment, $actor, &$result) {
                    $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

                    if ($locked->partner_id !== null) {
                        throw ValidationException::withMessages(['status' => 'الطرد مع شريكٍ آخر بالفعل.']);
                    }
                    if ($locked->status !== ShipmentStatus::AtHub) {
                        throw ValidationException::withMessages(['status' => 'يُسلَّم للشريك من «بالمخزن» فقط.']);
                    }

                    $locked->forceFill(['partner_id' => $partner->id])->save();
                    $this->change->handle($locked, ShipmentStatus::InTransit, $actor, [
                        'note' => 'سُلّم إلى '.$partner->name,
                    ]);

                    $result['moved'][] = $locked->number;
                });
            } catch (ValidationException $e) {
                $result['skipped'][$shipment->number] = collect($e->errors())->flatten()->first() ?? 'تعذّر التسليم';
            }
        }

        return $result;
    }
}
