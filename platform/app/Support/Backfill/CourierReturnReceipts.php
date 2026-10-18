<?php

namespace App\Support\Backfill;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * إيصالات راجع المندوبين لما استُلم قبلها: كلّ استلامٍ لراجع مندوبٍ بيد موظّفٍ في الدقيقة نفسها
 * إيصالٌ واحد، بأرقامٍ من تسلسل كلّ شركة (RC…). يُعاد بأمان: ما له إيصالٌ لا يُمسّ.
 */
class CourierReturnReceipts
{
    public static function run(): int
    {
        $rows = DB::table('shipments')
            ->whereNotNull('return_received_at')
            ->whereNull('courier_return_receipt_id')
            ->orderBy('company_id')->orderBy('return_received_at')->orderBy('id')
            ->get(['id', 'company_id', 'delivery_courier_id', 'return_received_by_user_id', 'return_received_at', 'cod_amount']);

        $groups = $rows->groupBy(fn ($row) => implode('|', [
            $row->company_id,
            $row->delivery_courier_id ?? '-',
            $row->return_received_by_user_id ?? '-',
            Carbon::parse($row->return_received_at)->format('Y-m-d H:i'),
        ]));

        $created = 0;

        foreach ($groups as $group) {
            DB::transaction(function () use ($group, &$created) {
                $first = $group->first();

                DB::table('shipments')->whereIn('id', $group->pluck('id'))->update([
                    'courier_return_receipt_id' => DB::table('courier_return_receipts')->insertGetId([
                        'company_id'          => $first->company_id,
                        'number'              => self::nextNumber((int) $first->company_id),
                        'courier_id'          => $first->delivery_courier_id,
                        'shipments_count'     => $group->count(),
                        'cod_total'           => (int) $group->sum('cod_amount'),
                        'received_at'         => $first->return_received_at,
                        'received_by_user_id' => $first->return_received_by_user_id,
                        'created_at'          => now(),
                        'updated_at'          => now(),
                    ]),
                ]);

                $created++;
            });
        }

        return $created;
    }

    /** الرقم التالي من تسلسل الشركة نفسه الذي يستعمله الاستلام (SequenceGenerator) */
    private static function nextNumber(int $companyId): string
    {
        $row = DB::table('sequences')->where('company_id', $companyId)->where('key', 'courier_return_receipt')->lockForUpdate()->first();

        if (! $row) {
            DB::table('sequences')->insert([
                'company_id' => $companyId, 'key' => 'courier_return_receipt', 'prefix' => 'RC',
                'next_value' => 2, 'pad' => 6, 'updated_at' => now(),
            ]);

            return 'RC'.str_pad('1', 6, '0', STR_PAD_LEFT);
        }

        DB::table('sequences')->where('id', $row->id)->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

        return $row->prefix.str_pad((string) $row->next_value, $row->pad, '0', STR_PAD_LEFT);
    }
}
