<?php

namespace App\Actions\Settlements;

use App\Models\AuditLog;
use App\Models\CourierSettlement;
use App\Models\CourierSettlementShipment;
use App\Models\MerchantSettlement;
use App\Models\MerchantSettlementShipment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SequenceGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تعديل الكشف المسودّة: يُحاسَب على شحناتٍ بعينها منه وتبقى البقية فيه، وتُضاف إليه شحناتٌ
 * تنتظر التسوية.
 *
 * المسودّة لقطةٌ بلا أثر، فتعديلها آمن: ما يُحاسَب عليه يُفصل في كشفٍ يُقفَل، وما أُضيف يُلتقط
 * سطرُه ساعةَ يدخل كما يُلتقط عند البناء، والمجاميع تُعاد من السطور.
 * أمّا المُقفَل فلا يُمسّ: تصحيحه حركةٌ في الدفتر.
 */
class EditDraftSettlement
{
    /** ما يُعرض للإضافة في صفحة المسودّة — أكثر منه يُضاف ببناء كشفٍ بعدها */
    public const ADDABLE_SHOWN = 300;

    public function __construct(protected SequenceGenerator $sequences) {}

    /**
     * مسودّةٌ بُنيت قبل أن يخرج الراجع من كشف المندوب: يُخرج منها راجعٌ لم يُسلَّم ولا أجرة له
     * — سطرٌ بصفرين لا يُحاسَب عليه شيء. ويعود له أن يُحاسَب إن تغيّر.
     */
    public function dropUnpaidReturns(CourierSettlement $settlement): int
    {
        if ($settlement->status !== 'draft') {
            return 0;
        }

        $dropped = $settlement->lines()->where('commission', 0)->where('collected_amount', 0)
            ->whereHas('shipment', fn ($q) => $q->where('status', \App\Enums\ShipmentStatus::Returned->value)->whereNull('delivered_at'))
            ->delete();

        if ($dropped > 0) {
            BuildCourierSettlement::refreshTotals($settlement->refresh());
        }

        return $dropped;
    }

    /**
     * «حاسب على المحدَّد»: المحدَّد من سطور المسودّة يُفصل في كشفٍ جديدٍ للطرف نفسه، يُقفله
     * المستدعي في المعاملة نفسها (ConfirmCourierSettlement، PayMerchantSettlement::confirm)،
     * وتبقى البقية في المسودّة برمزها. فإن حُدِّد الكل فالمسودّة نفسها هي ما يُقفَل.
     */
    public function split(CourierSettlement|MerchantSettlement $settlement, array $shipmentIds, ?User $actor): CourierSettlement|MerchantSettlement
    {
        $draft = $this->claim($settlement);

        $picked = $draft->lines()->whereIn('shipment_id', array_map('intval', $shipmentIds))->pluck('shipment_id');

        if ($picked->isEmpty()) {
            throw ValidationException::withMessages(['shipment_ids' => 'اختر شحنةً من سطور الكشف.']);
        }

        if ($picked->count() >= $draft->lines()->count()) {
            return $draft;
        }

        $courier = $draft instanceof CourierSettlement;
        $from = Shipment::whereIn('id', $picked)->min('status_changed_at');

        $part = $draft->newInstance()->forceFill([
            ($courier ? 'courier_id' : 'merchant_id') => $courier ? $draft->courier_id : $draft->merchant_id,
            'branch_id'          => $draft->branch_id,
            'code'               => $this->sequences->next($courier ? 'courier_settlement' : 'merchant_settlement'),
            'from_date'          => $from ? \Illuminate\Support\Carbon::parse($from)->toDateString() : $draft->from_date,
            'to_date'            => $draft->to_date,
            'status'             => 'draft',
            'created_by_user_id' => $actor?->id,
        ] + ($courier ? [] : ['payout_method' => $draft->payout_method]));
        $part->save();

        $draft->lines()->whereIn('shipment_id', $picked)->update([$draft->lines()->getForeignKeyName() => $part->id]);

        $this->refreshTotals($part);
        $this->refreshTotals($draft);

        $numbers = Shipment::whereIn('id', $picked)->orderBy('id')->pluck('number')->all();
        $this->audit($draft, $actor, ['settled' => $numbers, 'into' => $part->code]);

        return $part;
    }

    /** @return list<string> أرقام ما أُضيف */
    public function add(CourierSettlement|MerchantSettlement $settlement, array $shipmentIds, User $actor): array
    {
        return DB::transaction(function () use ($settlement, $shipmentIds, $actor) {
            $draft = $this->claim($settlement);

            $shipments = $this->addableQuery($draft)->whereIn('shipments.id', array_map('intval', $shipmentIds))->get();

            if ($shipments->isEmpty()) {
                throw ValidationException::withMessages([
                    'shipment_ids' => 'اختر من الشحنات التي تنتظر التسوية خارج الكشف.',
                ]);
            }

            foreach ($shipments as $shipment) {
                $draft instanceof CourierSettlement
                    ? CourierSettlementShipment::create(BuildCourierSettlement::line($draft, $shipment))
                    : MerchantSettlementShipment::create(BuildMerchantSettlement::line($draft, $shipment));
            }

            // فترة الكشف تتّسع لما أُضيف من قبلها
            $earliest = $shipments->min('status_changed_at')?->toDateString();
            if ($earliest && (! $draft->from_date || $earliest < $draft->from_date->toDateString())) {
                $draft->from_date = $earliest;
            }

            $this->refreshTotals($draft);

            $numbers = $shipments->pluck('number')->all();
            $this->audit($draft, $actor, ['added' => $numbers]);

            return $numbers;
        });
    }

    /**
     * ما ينتظر التسوية لصاحب الكشف ولم يدخله: سُلِّم أو رجع بعد بنائه.
     *
     * @return Collection<int, Shipment>
     */
    public function addable(CourierSettlement|MerchantSettlement $draft): Collection
    {
        return $this->addableQuery($draft)->with('governorate:id,name_ar')->limit(self::ADDABLE_SHOWN)->get();
    }

    public function addableCount(CourierSettlement|MerchantSettlement $draft): int
    {
        return $this->addableQuery($draft)->count();
    }

    /** @return Builder<Shipment> */
    protected function addableQuery(CourierSettlement|MerchantSettlement $draft): Builder
    {
        $query = $draft instanceof CourierSettlement
            ? app(BuildCourierSettlement::class)->eligibleQuery($draft->courier)
            : app(BuildMerchantSettlement::class)->eligibleQuery($draft->merchant);

        return $query->whereNotIn('shipments.id', $draft->lines()->select('shipment_id'));
    }

    /** الكشف من القاعدة بعد قفله: إقفالٌ سبق التعديل بلحظةٍ لا يُعدَّل كشفه */
    protected function claim(CourierSettlement|MerchantSettlement $settlement): CourierSettlement|MerchantSettlement
    {
        $draft = $settlement->newQuery()->lockForUpdate()->find($settlement->id);

        if (! $draft) {
            throw ValidationException::withMessages(['settlement' => "حُذف كشف {$settlement->code} من قبل."]);
        }

        if ($draft->status !== 'draft') {
            throw ValidationException::withMessages([
                'settlement' => "كشف {$draft->code} مُقفَل فلا يُعدَّل — تصحيحه حركةٌ في الدفتر.",
            ]);
        }

        return $draft;
    }

    protected function refreshTotals(CourierSettlement|MerchantSettlement $draft): void
    {
        $draft instanceof CourierSettlement
            ? BuildCourierSettlement::refreshTotals($draft)
            : BuildMerchantSettlement::refreshTotals($draft);
    }

    protected function audit(CourierSettlement|MerchantSettlement $draft, ?User $actor, array $change): void
    {
        AuditLog::create([
            'user_id'        => $actor?->id,
            'user_name'      => $actor?->name,
            'action'         => 'settlement_draft_edited',
            'auditable_type' => $draft::class,
            'auditable_id'   => $draft->id,
            'new_values'     => ['code' => $draft->code] + $change + [
                'shipments_count' => (int) $draft->shipments_count,
                'net_amount'      => (int) $draft->net_amount,
            ],
            'ip'             => request()->ip(),
        ]);
    }
}
