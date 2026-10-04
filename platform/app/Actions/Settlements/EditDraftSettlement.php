<?php

namespace App\Actions\Settlements;

use App\Models\AuditLog;
use App\Models\CourierSettlement;
use App\Models\CourierSettlementShipment;
use App\Models\MerchantSettlement;
use App\Models\MerchantSettlementShipment;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تعديل الكشف المسودّة: تُخرَج منه شحناتٌ بعينها، وتُضاف إليه شحناتٌ تنتظر التسوية.
 *
 * المسودّة لقطةٌ بلا أثر، فتعديلها آمن: ما أُخرج يبقى بلا تسوية ويدخل الكشف التالي،
 * وما أُضيف يُلتقط سطرُه ساعةَ يدخل كما يُلتقط عند البناء، والمجاميع تُعاد من السطور.
 * أمّا المُقفَل فلا يُمسّ: تصحيحه حركةٌ في الدفتر.
 */
class EditDraftSettlement
{
    /** ما يُعرض للإضافة في صفحة المسودّة — أكثر منه يُضاف ببناء كشفٍ بعدها */
    public const ADDABLE_SHOWN = 300;

    /** @return list<string> أرقام ما أُخرج */
    public function remove(CourierSettlement|MerchantSettlement $settlement, array $shipmentIds, User $actor): array
    {
        return DB::transaction(function () use ($settlement, $shipmentIds, $actor) {
            $draft = $this->claim($settlement);

            $lines = $draft->lines()->whereIn('shipment_id', array_map('intval', $shipmentIds))
                ->with('shipment:id,number')->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['shipment_ids' => 'اختر شحنةً من سطور الكشف.']);
            }

            if ($lines->count() >= $draft->lines()->count()) {
                throw ValidationException::withMessages([
                    'shipment_ids' => 'لا يبقى الكشف بلا شحنات — احذف الكشف كلّه بدل إخراج شحناته كلّها.',
                ]);
            }

            $draft->lines()->whereKey($lines->modelKeys())->delete();
            $this->refreshTotals($draft);

            $numbers = $lines->map(fn ($line) => $line->shipment?->number)->filter()->values()->all();
            $this->audit($draft, $actor, ['removed' => $numbers]);

            return $numbers;
        });
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
     * ما ينتظر التسوية لصاحب الكشف ولم يدخله: سُلِّم أو رجع بعد بنائه، أو أُخرج منه.
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

    protected function audit(CourierSettlement|MerchantSettlement $draft, User $actor, array $change): void
    {
        AuditLog::create([
            'user_id'        => $actor->id,
            'user_name'      => $actor->name,
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
