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
                    'pad'        => $this->defaultPad($key),
                    'updated_at' => now(),
                ]);

                return $this->format($this->defaultPrefix($key), 1, $this->defaultPad($key));
            }

            DB::table('sequences')
                ->where('id', $row->id)
                ->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return $this->format($row->prefix, $row->next_value, $row->pad);
        });
    }

    /**
     * يحجز $count رقماً متتالياً دفعةً ويُرجع أوّلها — لدفتر وصولاتٍ مطبوعة
     * (WaybillBook): مدىً واحد لا أرقامٌ تُطلب واحداً واحداً.
     */
    public function reserve(string $key, int $count, ?int $companyId = null): int
    {
        $companyId ??= Tenancy::id();

        abort_if($companyId === null, 500, 'حجز أرقامٍ متسلسلة بلا سياق شركة.');
        abort_if($count < 1, 500, 'الحجز لرقمٍ واحد على الأقل.');

        return DB::transaction(function () use ($key, $count, $companyId) {
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
                    'next_value' => $count + 1,
                    'pad'        => $this->defaultPad($key),
                    'updated_at' => now(),
                ]);

                return 1;
            }

            DB::table('sequences')
                ->where('id', $row->id)
                ->update(['next_value' => $row->next_value + $count, 'updated_at' => now()]);

            return (int) $row->next_value;
        });
    }

    protected function format(string $prefix, int $value, int $pad): string
    {
        return $prefix.str_pad((string) $value, $pad, '0', STR_PAD_LEFT);
    }

    protected function defaultPad(string $key): int
    {
        return in_array($key, ['collection_batch', 'profit_withdrawal', 'financial_period'], true) ? 4 : 6;
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
            'expense'             => 'EX',
            'return_batch'        => 'RB',
            'pickup_payout'       => 'PP',
            'branch_remittance'   => 'BR',
            'prepaid_receipt'     => 'PR',
            'merchant_advance'    => 'AD',
            'merchant_request'    => '',
            'shipment_ticket'     => 'TK',
            'courier_return_receipt' => 'RC',
            // «وجبات التحصيل والأرباح» وسحب الأرباح: COL-0001 وWD-0001 كما يُقال
            'collection_batch'    => 'COL-',
            'profit_withdrawal'   => 'WD-',
            'financial_period'    => 'FP-',
            default               => '',
        };
    }
}
