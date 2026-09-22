<?php

namespace App\Actions\Settlements;

use App\Enums\ShipmentStatus;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\CourierSettlementShipment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SequenceGenerator;
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
                    'courier_id' => "للمندوب {$courier->name} كشف مفتوح ({$open->code}). أقفِله أو ألغِه قبل بناء كشف جديد.",
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
                CourierSettlementShipment::create([
                    'courier_settlement_id' => $settlement->id,
                    'shipment_id'           => $shipment->id,
                    'collected_amount'      => $shipment->collected_amount,
                    'commission'            => $shipment->courier_commission,
                ]);
            }

            $settlement->forceFill([
                'shipments_count'  => $shipments->count(),
                'cod_total'        => (int) $shipments->sum('collected_amount'),
                'commission_total' => (int) $shipments->sum('courier_commission'),
                'deductions'       => 0,
            ]);

            $settlement->net_amount = $settlement->cod_total - $settlement->commission_total;
            $settlement->save();

            return $settlement;
        });
    }

    /** شحنات وصلت حالة نهائية على يد هذا المندوب ولم تُسوَّ بعد. */
    public function eligible(Courier $courier, array $options = [])
    {
        return Shipment::query()
            ->where('delivery_courier_id', $courier->id)
            ->whereNull('courier_settlement_id')
            ->whereIn('status', [
                ShipmentStatus::Delivered->value,
                ShipmentStatus::PartiallyDelivered->value,
                ShipmentStatus::Returned->value,
            ])
            ->when($options['from'] ?? null, fn ($q, $from) => $q->whereFromDate('status_changed_at', $from))
            ->when($options['to'] ?? null, fn ($q, $to) => $q->whereUntilDate('status_changed_at', $to))
            ->orderBy('id')
            ->get();
    }
}
