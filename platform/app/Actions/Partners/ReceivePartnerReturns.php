<?php

namespace App\Actions\Partners;

use App\Actions\Returns\ReceiveReturns;
use App\Enums\ShipmentStatus;
use App\Models\Partner;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * الراجع الذي عاد من الشريك إلينا (docs/plan/64): يُستلم بمسار الراجع نفسه، ويُفصل عن الشريك
 * فلا يبقى في قائمته. ما ليس راجعاً عنده، أو ليس له، يُتجاهل بصمت لا بخطأ.
 *
 * @param  list<int>  $shipmentIds
 * @return list<int> الطرود التي استُلمت
 */
class ReceivePartnerReturns
{
    public function __construct(protected ReceiveReturns $returns) {}

    public function handle(Partner $partner, array $shipmentIds, User $actor): array
    {
        return DB::transaction(function () use ($partner, $shipmentIds, $actor) {
            $ids = Shipment::query()
                ->whereIn('id', $shipmentIds)
                ->where('partner_id', $partner->id)
                ->where('status', ShipmentStatus::Returning->value)
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return [];
            }

            Shipment::query()->whereIn('id', $ids)->update(['partner_id' => null]);

            $this->returns->handle($ids, $actor);

            return $ids;
        });
    }
}
