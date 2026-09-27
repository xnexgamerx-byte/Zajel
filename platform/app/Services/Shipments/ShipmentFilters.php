<?php

namespace App\Services\Shipments;

use App\Models\Hub;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * فلاتر قائمة الشحنات، كما في «عرض كل شحنات العميل» و«شحناتي» في المعتاد:
 * المرحلة وتاريخ دخولها، وتمّ التحاسب؟، وسبب الراجع أو التأجيل، والمنطقة،
 * والاستبدال، ومندوب الاستلام، والفرع المنشئ والحاليّ، والمبلغ.
 *
 * مصدرٌ واحد للقائمة وتصديرها: ما يُصدَّر هو ما يُرى، لا استعلامٌ ثانٍ يختلف
 * عنه في شرطٍ نُسي.
 */
final class ShipmentFilters
{
    /** ما يُقرأ من الطلب — وما عداه لا يمسّ الاستعلام. */
    public const KEYS = [
        'q', 'stage', 'status', 'merchant_id', 'governorate_id', 'city_id', 'courier_id', 'pickup_courier_id',
        'from', 'to', 'stage_from', 'stage_to', 'settled', 'reason_id', 'type', 'branch_id', 'current_branch_id',
        'amount',
    ];

    /** ما يُطوى تحت «بحث متقدّم»: إن وُجد أحدها يُفتح. */
    public const ADVANCED = [
        'city_id', 'pickup_courier_id', 'stage_from', 'stage_to', 'settled', 'reason_id', 'type', 'branch_id',
        'current_branch_id', 'amount',
    ];

    public static function apply(Builder $query, Request $request): Builder
    {
        $value = fn (string $key) => filled($request->query($key)) ? $request->query($key) : null;
        $id = fn (string $key) => is_numeric($value($key)) ? (int) $value($key) : null;

        $query->search($value('q'));

        ShipmentStages::apply($query, $value('stage'));

        foreach ([
            'status'            => 'shipments.status',
            'type'              => 'shipments.type',
        ] as $key => $column) {
            if (is_string($v = $value($key))) {
                $query->where($column, $v);
            }
        }

        foreach ([
            'merchant_id'       => 'shipments.merchant_id',
            'governorate_id'    => 'shipments.governorate_id',
            'city_id'           => 'shipments.city_id',
            'courier_id'        => 'shipments.delivery_courier_id',
            'pickup_courier_id' => 'shipments.pickup_courier_id',
            'reason_id'         => 'shipments.last_failure_reason_id',
            'branch_id'         => 'shipments.branch_id',
        ] as $key => $column) {
            if (($v = $id($key)) !== null) {
                $query->where($column, $v);
            }
        }

        // حالياً في فرع: مكانها الآن مركزٌ من مراكزه
        if (($branch = $id('current_branch_id')) !== null) {
            $query->whereIn('shipments.hub_id', Hub::query()->select('id')->where('branch_id', $branch));
        }

        foreach (['from' => 'whereFromDate', 'to' => 'whereUntilDate'] as $key => $macro) {
            if (static::isDate($v = $value($key))) {
                $query->{$macro}('shipments.created_at', $v);
            }
        }

        // دخول المرحلة: آخر تغيّرٍ في الحالة
        foreach (['stage_from' => 'whereFromDate', 'stage_to' => 'whereUntilDate'] as $key => $macro) {
            if (static::isDate($v = $value($key))) {
                $query->{$macro}('shipments.status_changed_at', $v);
            }
        }

        // تمّ التحاسب؟ مع التاجر
        match ($value('settled')) {
            'yes'   => $query->whereNotNull('shipments.merchant_settled_at'),
            'no'    => $query->whereNull('shipments.merchant_settled_at'),
            default => null,
        };

        // المبلغ كما يُكتب: «٢٥٬٠٠٠» أو «25,000»
        if (($amount = $value('amount')) !== null) {
            $digits = preg_replace('/[^\d]/', '', Phone::latinDigits((string) $amount));

            if ($digits !== '') {
                $query->where('shipments.cod_amount', (int) $digits);
            }
        }

        return $query;
    }

    /** @return array<string, string> الفلاتر المختارة، لروابط الصفحات والتصدير */
    public static function active(Request $request): array
    {
        return array_filter($request->only(self::KEYS), fn ($v) => is_string($v) && $v !== '');
    }

    public static function hasAdvanced(Request $request): bool
    {
        return (bool) array_intersect(array_keys(static::active($request)), self::ADVANCED);
    }

    private static function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false;
    }
}
