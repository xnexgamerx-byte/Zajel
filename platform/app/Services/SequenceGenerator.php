<?php

namespace App\Services;

use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * توليد أرقام متسلسلة آمنة تحت التزاحم.
 *
 * يجب أن يُستدعى داخل معاملة (transaction) قائمة؛ القفل يُحرَّر عند إغلاقها.
 */
class SequenceGenerator
{
    public function next(string $key, ?int $companyId = null): string
    {
        $companyId ??= Tenancy::id();

        abort_if($companyId === null, 500, 'توليد رقم متسلسل بلا سياق شركة.');

        return DB::transaction(function () use ($key, $companyId) {
            $row = DB::table('sequences')
                ->where('company_id', $companyId)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                DB::table('sequences')->insert([
                    'company_id' => $companyId,
                    'key'        => $key,
                    'prefix'     => $this->defaultPrefix($key),
                    'next_value' => 2,
                    'pad'        => 6,
                    'updated_at' => now(),
                ]);

                return $this->format($this->defaultPrefix($key), 1, 6);
            }

            DB::table('sequences')
                ->where('id', $row->id)
                ->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return $this->format($row->prefix, $row->next_value, $row->pad);
        });
    }

    protected function format(string $prefix, int $value, int $pad): string
    {
        return $prefix.str_pad((string) $value, $pad, '0', STR_PAD_LEFT);
    }

    protected function defaultPrefix(string $key): string
    {
        return match ($key) {
            'merchant'            => 'M',
            'courier'             => 'C',
            'bag'                 => 'BG',
            'manifest'            => 'MF',
            'pickup_request'      => 'PU',
            'courier_settlement'  => 'CS',
            'merchant_settlement' => 'MS',
            default               => '',
        };
    }
}
