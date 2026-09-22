<?php

namespace App\Http\Controllers\Portal\Concerns;

use App\Enums\ShipmentStatus;
use App\Models\Merchant;
use App\Models\Shipment;

trait ReadsMerchantStats
{
    /**
     * عدّادات التاجر في استعلام واحد مجمَّع.
     *
     * toBase() لأن status يُحوَّل إلى enum، فمقارنته بقائمة نصوص لا تطابق.
     */
    protected function statusCounts(Merchant $merchant): array
    {
        $rows = Shipment::query()
            ->where('merchant_id', $merchant->id)
            ->selectRaw('status, count(*) as c, sum(cod_amount) as cod')
            ->groupBy('status')
            ->toBase()
            ->get();

        $open = $rows->whereIn('status', ShipmentStatus::openValues());

        $of = fn (ShipmentStatus $s) => (int) $rows->where('status', $s->value)->sum('c');

        return [
            'total'     => (int) $rows->sum('c'),
            'open'      => (int) $open->sum('c'),
            'delivered' => $of(ShipmentStatus::Delivered),
            'returned'  => $of(ShipmentStatus::Returned),
            'cod_open'  => (int) $open->sum('cod'),
        ];
    }
}
