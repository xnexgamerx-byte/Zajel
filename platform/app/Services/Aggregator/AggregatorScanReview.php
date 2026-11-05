<?php

namespace App\Services\Aggregator;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\ShipmentLabelController;
use App\Models\AggregatorJob;
use App\Models\AggregatorLink;
use App\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «مراجعة مسح الوسيط» (docs/plan/121): كلّ شحنةٍ مفتوحةٍ مع المندوب الوسيط المربوط، وأيقرأ مندوبهم ما على طردها.
 *
 * نظامهم يجد الطلب برقمه عندهم (AggregatorLink::companyOrderId: البادئة ثمّ رقم الوصل)، فلا يُقرأ الطرد إلّا إن:
 * - أُنشئ الطلب عندهم أصلاً (aggregator_order_id). وإلّا: لم يُرسل، أو ينتظر الإرسال، أو رفضه نظامهم بسببه.
 * - وكان باركود الطرد هو ذلك الرقم نفسه. وإلّا:
 *   - ورقة دفتر الوصولات وحدها: برمز الدفتر (90001234)، لا يعرفونه.
 *   - أو وصلٌ طُبع بغير رقمهم: قبل البادئة (docs/plan/109)، أو لوصلٍ مطبوعٍ مسبقاً قبل هذا الإصلاح.
 *   - أو لم يُطبع له وصلٌ من النظام أصلاً.
 *
 * وما طُبع منذ docs/plan/121 يُعرف رمزه بالضبط (shipments.label_code). وما قبله يُقدَّر من تاريخ طباعته.
 */
final class AggregatorScanReview
{
    /** منذ هذا الوقت يحمل الوصل البادئة (الالتزام 7bd0eac، docs/plan/109): ما طُبع قبله لا يحملها يقيناً */
    public const PREFIX_SINCE = '2026-11-03 09:36:43';

    /** المشكلة ← ما يُقال عنها، وما يُفعل */
    public const PROBLEMS = [
        'not_sent' => 'لم تُرسل إلى نظامهم',
        'pending' => 'تنتظر الإرسال إلى نظامهم',
        'failed' => 'رفضها نظامهم أو تعذّر إرسالها',
        'missing' => 'نظامهم لا يجدها برقمها',
        'paper' => 'على الطرد ورقة الدفتر وحدها',
        'old_label' => 'وصلها المطبوع بغير رقمهم',
        'no_label' => 'لم يُطبع لها وصلٌ من النظام',
    ];

    /** ما يُصلحه طبع الوصل */
    public const LABEL_PROBLEMS = ['paper', 'old_label', 'no_label'];

    /** ما يُصلحه الإرسال */
    public const SEND_PROBLEMS = ['not_sent', 'failed'];

    /**
     * صفٌّ لكلّ شحنة: [shipment, expected, label, problems, error, found].
     *
     * @param  array<int, bool>  $verified  ما سُئل عنه نظامهم: معرّف الشحنة ← وُجد أم لا
     * @return Collection<int, array{shipment: Shipment, expected: string, label: ?string, problems: list<string>, error: ?string, found: ?bool}>
     */
    public function rows(AggregatorLink $link, array $verified = []): Collection
    {
        // بلا مندوبٍ وسيطٍ مختار لا شحنات له — لا «كلّ ما بلا مندوب»
        if (! $link->courier_id) {
            return collect();
        }

        $shipments = Shipment::query()
            ->where('delivery_courier_id', $link->courier_id)
            ->whereIn('status', ShipmentStatus::openValues())
            ->with(['merchant:id,business_name,code'])
            ->orderBy('merchant_id')->orderBy('id')
            ->get();

        $jobs = AggregatorJob::query()->where('action', 'create')
            ->whereIn('shipment_id', $shipments->pluck('id'))
            ->orderBy('id')->get()->keyBy('shipment_id'); // آخر عملٍ لكلّ شحنة

        $prefix = filled($link->order_prefix) ? (string) $link->order_prefix : null;
        $since = Carbon::parse(self::PREFIX_SINCE, 'UTC');

        return $shipments->map(function (Shipment $shipment) use ($link, $jobs, $prefix, $since, $verified) {
            $expected = $link->companyOrderId($shipment);
            $job = $jobs->get($shipment->id);
            $problems = [];
            $error = null;

            if (blank($shipment->aggregator_order_id)) {
                // ما فشل ويُعاد بمهلةٍ تطول يبقى «ينتظر» بسبب فشله: هو مرفوضٌ الآن
                $problems[] = match (true) {
                    $job === null || $job->status === 'done' => 'not_sent',
                    $job->status === 'failed' || filled($job->last_error) => 'failed',
                    default => 'pending',
                };
                $error = $job?->status !== 'done' ? $job?->last_error : null;
            }

            $found = array_key_exists($shipment->id, $verified) ? $verified[$shipment->id] : null;
            if ($found === false && filled($shipment->aggregator_order_id)) {
                $problems[] = 'missing';
            }

            $label = self::onLabel($shipment, $prefix, $since);
            if ($label !== $expected) {
                $problems[] = match (true) {
                    $label === null && ShipmentLabelController::preprinted($shipment) => 'paper',
                    $label === null => 'no_label',
                    default => 'old_label',
                };
            }

            return compact('shipment', 'expected', 'label', 'problems', 'error', 'found');
        });
    }

    /**
     * الرمز في باركود آخر وصلٍ طُبع للشحنة من النظام، أو null إن لم يُطبع لها وصل.
     * ما طُبع منذ docs/plan/121 محفوظٌ كما طُبع؛ وما قبله:
     * - وصلٌ مطبوعٌ مسبقاً: كان يُطبع برمزه هو.
     * - وغيره: برقمه وحده قبل البادئة، وبعدها ببادئة الربط.
     */
    public static function onLabel(Shipment $shipment, ?string $prefix, Carbon $since): ?string
    {
        if (filled($shipment->label_code)) {
            return (string) $shipment->label_code;
        }

        if ($shipment->printed_at === null) {
            return null;
        }

        if (ShipmentLabelController::preprinted($shipment)) {
            return (string) $shipment->barcode;
        }

        return $prefix !== null && $shipment->printed_at->gte($since) ? $prefix.$shipment->number : $shipment->number;
    }

    /**
     * ما يُعدّ لكلّ تاجر: الكلّ، والمقروء، وكلّ مشكلة.
     *
     * @param  Collection<int, array>  $rows
     * @return Collection<int, array{merchant: string, total: int, ok: int, counts: array<string, int>}>
     */
    public static function byMerchant(Collection $rows): Collection
    {
        return $rows->groupBy(fn ($row) => (int) $row['shipment']->merchant_id)
            ->map(function (Collection $group) {
                $counts = [];
                foreach ($group as $row) {
                    foreach ($row['problems'] as $problem) {
                        $counts[$problem] = ($counts[$problem] ?? 0) + 1;
                    }
                }

                return [
                    'merchant' => (string) ($group->first()['shipment']->merchant?->business_name ?? '—'),
                    'total' => $group->count(),
                    'ok' => $group->filter(fn ($row) => $row['problems'] === [])->count(),
                    'counts' => $counts,
                ];
            })
            ->sortByDesc(fn ($m) => $m['total'] - $m['ok'])
            ->values();
    }
}
