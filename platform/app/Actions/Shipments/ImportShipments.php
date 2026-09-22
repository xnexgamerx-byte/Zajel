<?php

namespace App\Actions\Shipments;

use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * إنشاء الشحنات من صفوف الملف بعد التحقّق.
 *
 * كلّها في معاملة واحدة: ملف من 300 صفّ إمّا يدخل كاملاً أو لا يدخل،
 * فلا يبقى التاجر يتساءل أيّ صفّ وصل وأيّ صفّ لا.
 */
class ImportShipments
{
    public function __construct(protected CreateShipment $create) {}

    /**
     * @param  Collection<int, array{row:int, data:array, errors:array}>  $rows
     * @return Collection<int, Shipment>
     */
    public function handle(Collection $rows, Merchant $merchant, ?User $actor, string $source = 'import'): Collection
    {
        $valid = $rows->filter(fn (array $row) => empty($row['errors']));

        return DB::transaction(function () use ($valid, $merchant, $actor, $source) {
            return $valid->map(fn (array $row) => $this->create->handle(
                $row['data'] + ['merchant_id' => $merchant->id, 'source' => $source],
                $actor,
            ));
        });
    }

    /** أرقام الطلبات المستعملة سلفاً عند هذا التاجر — تكرارها يعني شحنة مزدوجة. */
    public function existingReferences(Collection $rows, Merchant $merchant): array
    {
        $references = $rows
            ->pluck('data.merchant_reference')
            ->filter()
            ->unique()
            ->values();

        if ($references->isEmpty()) {
            return [];
        }

        return Shipment::query()
            ->where('merchant_id', $merchant->id)
            ->whereIn('merchant_reference', $references)
            ->pluck('number', 'merchant_reference')
            ->all();
    }
}
