<?php

namespace App\Actions\Shipments;

use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Enums\ShipmentStatus;
use App\Models\CourierSettlementShipment;
use App\Models\MerchantSettlementShipment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\Ledger;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تعديل أجور الشحنة وطلبيتها بصلاحيةٍ خاصّة، أيّاً كانت حالها (docs/plan/38).
 *
 * «تعديل البيانات» العاديّ يقف عند التسليم: بعده قُيِّد المال. وهذا لصاحب الشركة
 * (ومن يمنحه) يصحّح أجرة التوصيل على التاجر، وأجرة الراجع، وأجرة المندوب، وبيانات
 * الطلبية — ولو انتهت الشحنة أو علقت بمشكلة. ولا يُغيَّر رقمٌ قُيِّد بصمت: فرق مستحقّ
 * التاجر قيدٌ في حسابه (fee_correction)، وفرق أجرة المندوب قيدٌ في عمولته، بالسبب المكتوب.
 *
 * وما دخل كشفاً أُقفِل لا تتغيّر أجوره هنا: الكشف دُفع برقمه. يُحذف الكشف أوّلاً في
 * مهلته، أو يُصحَّح الفرق في الكشف التالي. وما في مسودّة كشفٍ يُحدَّث سطرها معه.
 */
class OverrideShipment
{
    /** بيانات الطلبية التي تُصحَّح بعد الإقفال: لا الوجهة ولا المبلغ — لهما طريقاهما */
    public const DETAILS = [
        'recipient_name', 'recipient_phone', 'recipient_phone_alt', 'address', 'landmark',
        'description', 'pieces_count', 'notes', 'merchant_reference',
    ];

    public const FEES = [
        'delivery_fee'       => 'أجرة التوصيل على التاجر',
        'return_fee'         => 'أجرة الراجع',
        'courier_commission' => 'أجرة المندوب',
    ];

    public function __construct(protected Ledger $ledger) {}

    /** هل قُيِّد مال التاجر عنها: سُلِّمت (ولو بعضها) أو رجعت إليه */
    public static function merchantPosted(Shipment $shipment): bool
    {
        return $shipment->wasDelivered() || $shipment->status === ShipmentStatus::Returned;
    }

    /** هل قُيِّدت عمولة المندوب عنها: عند التسليم، أو عند الرجوع لما لم يُسلَّم منه شيء */
    public static function commissionPosted(Shipment $shipment): bool
    {
        return $shipment->delivery_courier_id !== null
            && ($shipment->wasDelivered() || $shipment->status === ShipmentStatus::Returned);
    }

    public function handle(Shipment $shipment, array $data, User $actor, string $reason): Shipment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب التعديل: يبقى في سجلّ الشحنة وفي الحساب.']);
        }

        return DB::transaction(function () use ($shipment, $data, $actor, $reason) {
            $shipment = Shipment::query()->with('merchant', 'deliveryCourier')->lockForUpdate()->findOrFail($shipment->id);
            $before = $shipment->only([...self::DETAILS, ...array_keys(self::FEES), 'total_fees', 'merchant_due']);

            foreach (self::DETAILS as $field) {
                if (array_key_exists($field, $data)) {
                    $shipment->{$field} = match ($field) {
                        'recipient_name' => filled($data[$field]) ? $data[$field] : Shipment::UNNAMED_RECIPIENT,
                        'pieces_count'   => max(1, (int) $data[$field]),
                        'address', 'landmark' => (string) ($data[$field] ?? ''),
                        default          => $data[$field],
                    };
                }
            }

            $merchantDelta = $this->applyMerchantFees($shipment, $data);
            $commissionDelta = $this->applyCourierFee($shipment, $data);

            if (! $shipment->isDirty()) {
                return $shipment;
            }

            if ($merchantDelta !== 0 && $shipment->merchant_settlement_id) {
                throw ValidationException::withMessages(['delivery_fee' => 'دخلت الشحنة كشف تاجرٍ أُقفِل: أجورها دُفعت برقمها. احذف الكشف في مهلته أوّلاً.']);
            }

            if ($commissionDelta !== 0 && $shipment->courier_settlement_id) {
                throw ValidationException::withMessages(['courier_commission' => 'دخلت الشحنة كشف مندوبٍ أُقفِل: عمولتها دُفعت. احذف الكشف في مهلته أوّلاً.']);
            }

            $shipment->save();

            if ($merchantDelta !== 0) {
                $this->ledger->recordFeeCorrection($shipment, $merchantDelta, $actor, $reason);
            }

            if ($commissionDelta !== 0) {
                $this->ledger->recordCommissionCorrection($shipment, $commissionDelta, $actor, $reason);
            }

            $this->refreshDraftLines($shipment);

            $changes = [];
            foreach ($before as $field => $from) {
                $to = $shipment->getAttribute($field);
                if ((string) $from !== (string) $to) {
                    $changes[$field] = ['from' => $from, 'to' => $to];
                }
            }

            ShipmentEvent::create([
                'shipment_id' => $shipment->id,
                'from_status' => $shipment->status->value,
                'to_status'   => $shipment->status->value,
                'event_type'  => 'edited',
                'actor_type'  => 'user',
                'actor_id'    => $actor->id,
                'actor_name'  => $actor->name,
                'note'        => 'تعديل بصلاحية خاصّة — '.$reason.$this->describe($changes),
                'meta'        => ['changes' => $changes, 'override' => true],
                'ip'          => request()->ip(),
            ]);

            return $shipment->refresh();
        });
    }

    /**
     * أجرة التوصيل والراجع، ومستحقّ التاجر منهما. يعيد فرق المستحقّ الذي يُقيَّد —
     * صفراً لما لم يُقيَّد ماله بعد (يُقيَّد كاملاً عند التسليم أو الرجوع).
     */
    protected function applyMerchantFees(Shipment $shipment, array $data): int
    {
        $delivery = $this->fee($data, 'delivery_fee') ?? (int) $shipment->delivery_fee;
        $return = $this->fee($data, 'return_fee') ?? (int) $shipment->return_fee;

        if ($delivery === (int) $shipment->delivery_fee && $return === (int) $shipment->return_fee) {
            return 0;
        }

        $oldDue = (int) $shipment->merchant_due;
        $oldTotal = (int) $shipment->total_fees;

        $shipment->delivery_fee = $delivery;
        $shipment->return_fee = $return;
        $totalFees = max(0, $delivery + (int) $shipment->extra_fee + (int) $shipment->cod_fee - (int) $shipment->discount);
        $shipment->total_fees = $totalFees;

        if (! self::merchantPosted($shipment)) {
            // لم يُقيَّد شيء: يُحسب المستحقّ من جديد كما عند الإنشاء، وما قُبض مقدّماً يبقى له
            $shipment->merchant_due = PricingService::totals(
                (int) $shipment->cod_amount, (string) $shipment->fees_paid_by, $delivery,
                (int) $shipment->extra_fee, (int) $shipment->cod_fee, (int) $shipment->discount,
            )['merchant_due'] + (int) $shipment->prepaid_amount;

            return 0;
        }

        $newDue = match (true) {
            // سُلِّمت: أجرة التوصيل من محصَّلها إن كانت على التاجر؛ وعلى الزبون لا تمسّ مستحقّه
            $shipment->wasDelivered() => $shipment->fees_paid_by === 'customer'
                ? $oldDue
                : $oldDue - ($totalFees - $oldTotal),
            // رجعت ولم يُسلَّم منها شيء: عليه أجرة رجوعها وحدها
            default => $oldDue - ($return - (int) $shipment->getOriginal('return_fee')),
        };

        $shipment->merchant_due = $newDue;

        return $newDue - $oldDue;
    }

    /** أجرة المندوب: تُثبَّت فلا يمحوها التسليم، ويعيد فرقها إن كانت قُيِّدت. */
    protected function applyCourierFee(Shipment $shipment, array $data): int
    {
        $fee = $this->fee($data, 'courier_commission');

        if ($fee === null || $fee === (int) $shipment->courier_commission) {
            return 0;
        }

        $delta = $fee - (int) $shipment->courier_commission;

        $shipment->courier_commission = $fee;
        $shipment->courier_commission_fixed = true;

        return self::commissionPosted($shipment) ? $delta : 0;
    }

    /** سطر الشحنة في مسودّة كشفٍ: لقطةٌ تُعاد من الشحنة بعد تعديلها. */
    protected function refreshDraftLines(Shipment $shipment): void
    {
        $merchantLine = MerchantSettlementShipment::query()->where('shipment_id', $shipment->id)
            ->whereHas('settlement', fn ($q) => $q->where('status', 'draft'))->with('settlement')->first();

        if ($merchantLine) {
            $merchantLine->forceFill(BuildMerchantSettlement::line($merchantLine->settlement, $shipment))->save();
            BuildMerchantSettlement::refreshTotals($merchantLine->settlement);
        }

        $courierLine = CourierSettlementShipment::query()->where('shipment_id', $shipment->id)
            ->whereHas('settlement', fn ($q) => $q->where('status', 'draft'))->with('settlement')->first();

        if ($courierLine) {
            $courierLine->forceFill(BuildCourierSettlement::line($courierLine->settlement, $shipment))->save();
            BuildCourierSettlement::refreshTotals($courierLine->settlement);
        }
    }

    protected function fee(array $data, string $field): ?int
    {
        return isset($data[$field]) && $data[$field] !== '' ? max(0, (int) $data[$field]) : null;
    }

    /** @param  array<string, array{from: mixed, to: mixed}>  $changes */
    protected function describe(array $changes): string
    {
        $labels = self::FEES + ['merchant_due' => 'مستحقّ التاجر'] + array_intersect_key(UpdateShipment::FIELDS, array_flip(self::DETAILS));
        $parts = [];

        foreach ($changes as $field => ['from' => $from, 'to' => $to]) {
            if (! isset($labels[$field])) {
                continue;
            }

            $parts[] = in_array($field, ['recipient_phone', 'recipient_phone_alt'], true)
                ? $labels[$field].': …'.substr((string) $from, -3).' ← …'.substr((string) $to, -3)
                : $labels[$field].': '.(is_numeric($from) ? number_format((int) $from) : ($from ?: '—'))
                    .' ← '.(is_numeric($to) ? number_format((int) $to) : ($to ?: '—'));
        }

        return $parts ? ' — '.implode(' · ', $parts) : '';
    }
}
