<?php

namespace App\Actions\Settlements;

use App\Enums\ShipmentStatus;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\CourierSettlementShipment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SequenceGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * يفتح كشف تسوية لمندوب: يجمع كل شحنة أُغلقت على يده ولم تدخل كشفاً بعد.
 *
 * السطور لقطة مُجمَّدة — لو تغيّرت تسعيرة التاجر غداً لا يتغيّر كشف مُقفَل،
 * ولو سُئل المندوب بعد شهر "من وين طلع هذا الرقم؟" يكون الجواب في السطور.
 */
class BuildCourierSettlement
{
    public function __construct(protected SequenceGenerator $sequences) {}

    public function handle(Courier $courier, ?User $actor = null, array $options = []): CourierSettlement
    {
        return DB::transaction(function () use ($courier, $actor, $options) {
            /*
            | كشف مفتوح واحد لكل طرف.
            |
            | الشحنات لا تُوسَم إلّا عند الإقفال، فبناء كشف ثانٍ قبل إقفال
            | الأول يلتقط الشحنات نفسها: كشفان بالمبلغ نفسه، وإقفالهما
            | يدفع مرّتين. وُجد بالتجربة على ١٦٣٧٦ شحنة و٩٧٣ مليوناً.
            */
            $open = CourierSettlement::where('courier_id', $courier->id)
                ->where('status', 'draft')
                ->lockForUpdate()
                ->first();

            if ($open) {
                throw ValidationException::withMessages([
                    'courier_id' => "للمندوب {$courier->name} كشفٌ مسودّة ({$open->code}). أقفِله أو احذفه من صفحته قبل بناء كشف جديد.",
                ]);
            }

            $shipments = $this->eligible($courier, $options);

            if ($shipments->isEmpty()) {
                throw ValidationException::withMessages([
                    'courier_id' => "لا توجد شحنات غير مسوّاة للمندوب {$courier->name}.",
                ]);
            }

            $settlement = CourierSettlement::create([
                'courier_id'         => $courier->id,
                'branch_id'          => $courier->branch_id,
                'code'               => $this->sequences->next('courier_settlement'),
                'from_date'          => $options['from'] ?? $shipments->min('status_changed_at')?->toDateString(),
                'to_date'            => $options['to'] ?? now()->toDateString(),
                'status'             => 'draft',
                'created_by_user_id' => $actor?->id,
            ]);

            foreach ($shipments as $shipment) {
                CourierSettlementShipment::create(static::line($settlement, $shipment));
            }

            static::refreshTotals($settlement);

            return $settlement;
        });
    }

    /** سطر الكشف: لقطةٌ مجمَّدة من الشحنة ساعةَ تدخله — عند البناء، وحين تُضاف إلى المسودّة */
    public static function line(CourierSettlement $settlement, Shipment $shipment): array
    {
        return [
            'courier_settlement_id' => $settlement->id,
            'shipment_id'           => $shipment->id,
            'collected_amount'      => $shipment->collected_amount,
            'commission'            => $shipment->courier_commission,
        ];
    }

    /** مجاميع الكشف من سطوره: عند البناء، وبعد كل إخراجٍ أو إضافةٍ في المسودّة */
    public static function refreshTotals(CourierSettlement $settlement): void
    {
        $sums = $settlement->lines()->toBase()->selectRaw(
            'count(*) as n, coalesce(sum(collected_amount), 0) as cod, coalesce(sum(commission), 0) as commission'
        )->first();

        $settlement->forceFill([
            'shipments_count'  => (int) $sums->n,
            'cod_total'        => (int) $sums->cod,
            'commission_total' => (int) $sums->commission,
            'deductions'       => (int) $settlement->deductions,
            'net_amount'       => (int) $sums->cod - (int) $sums->commission + (int) $settlement->deductions,
        ])->save();
    }

    /**
     * شحنات وصلت حالة نهائية على يد هذا المندوب ولم تُسوَّ بعد — والواصل الجزئي منذ
     * تسليمه: نقده بيد المندوب وعمولته ثبتت، وباقيه في طريقه لتاجره لا مال فيه.
     */
    public function eligible(Courier $courier, array $options = [])
    {
        return $this->eligibleQuery($courier, $options)->get();
    }

    /** @return Builder<Shipment> */
    public function eligibleQuery(Courier $courier, array $options = []): Builder
    {
        return Shipment::query()
            ->where('delivery_courier_id', $courier->id)
            ->whereNull('courier_settlement_id')
            ->where(fn ($q) => $q->whereIn('status', [
                ShipmentStatus::Delivered->value,
                ShipmentStatus::PartiallyDelivered->value,
                ShipmentStatus::Returned->value,
            ])->orWhereNotNull('delivered_at'))
            ->when($options['from'] ?? null, fn ($q, $from) => $q->whereFromDate('status_changed_at', $from))
            ->when($options['to'] ?? null, fn ($q, $to) => $q->whereUntilDate('status_changed_at', $to))
            ->orderBy('id');
    }
}
