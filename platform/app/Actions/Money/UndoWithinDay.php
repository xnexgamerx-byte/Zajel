<?php

namespace App\Actions\Money;

use App\Models\CashMovement;
use App\Models\CourierSettlement;
use App\Models\MerchantAdvance;
use App\Models\MerchantAdvanceRecovery;
use App\Models\MerchantSettlement;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashBook;
use App\Services\Ledger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «حذف كشف أو حركة مالية خلال ٢٤ ساعة فقط، وبعدها لا تُعدَّل» (docs/plan/38).
 *
 * لا يُمحى شيءٌ من الدفتر ولا من الصندوق: كل قيدٍ كتبه الكشف أو الحركة تُكتب له حركةٌ
 * معاكسة بسبب الحذف (Ledger::reverse، CashBook::reverse)، فيعود كل رصيدٍ كما كان،
 * ويبقى في السجلّ ما كان ومن ألغاه ولماذا. والكشف يبقى صفّه «ملغى»، وتعود شحناته
 * لكشفٍ جديد. وبعد يومٍ من إقفاله أو كتابتها لا يُلغى شيء: يُصحَّح بحركةٍ جديدة.
 */
class UndoWithinDay
{
    public const HOURS = 24;

    /** ما يُلغى من حركات الصندوق من شاشته؛ وما سواها يُلغى من مصدره (كشف، مصروف، سلفة) */
    public const CASH_CATEGORIES = ['transfer_out', 'transfer_in', 'adjustment'];

    public function __construct(protected Ledger $ledger, protected CashBook $cash) {}

    /** هل ما زال في مهلته */
    public static function open(?CarbonInterface $at): bool
    {
        return $at !== null && $at->greaterThan(now()->subHours(self::HOURS));
    }

    /** ما بقي من المهلة، للعرض: «تُلغى حتى ١٤:٣٠ غداً» */
    public static function until(?CarbonInterface $at): ?CarbonInterface
    {
        return $at?->copy()->addHours(self::HOURS);
    }

    public function courierSettlement(CourierSettlement $settlement, User $actor, string $reason): CourierSettlement
    {
        return DB::transaction(function () use ($settlement, $actor, $reason) {
            $settlement = CourierSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            $this->guardSettlement($settlement->status, $settlement->confirmed_at, $settlement->code, $reason);

            $this->reverseAll('courier_settlement', $settlement->id, $actor, "حذف الكشف {$settlement->code} — {$reason}");
            $this->freeShipments('courier_settlement_id', 'courier_settled_at', $settlement->id, "أُلغي كشف المندوب {$settlement->code}", $actor);
            $this->markCancelled($settlement, $actor, $reason);

            return $settlement->refresh();
        });
    }

    public function merchantSettlement(MerchantSettlement $settlement, User $actor, string $reason): MerchantSettlement
    {
        return DB::transaction(function () use ($settlement, $actor, $reason) {
            $settlement = MerchantSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            $this->guardSettlement($settlement->status, $settlement->confirmed_at, $settlement->code, $reason);

            $this->reverseAll('merchant_settlement', $settlement->id, $actor, "حذف الكشف {$settlement->code} — {$reason}");
            $this->restoreAdvances($settlement, $actor);
            $this->freeShipments('merchant_settlement_id', 'merchant_settled_at', $settlement->id, "أُلغي كشف التاجر {$settlement->code}", $actor);
            $this->markCancelled($settlement, $actor, $reason);

            return $settlement->refresh();
        });
    }

    /** مناقلةٌ بين صندوقين (بطرفيها) أو تسوية جرد، في يومها. */
    public function cashMovement(CashMovement $movement, User $actor, string $reason): void
    {
        DB::transaction(function () use ($movement, $actor, $reason) {
            $this->guardReason($reason);

            if (! in_array($movement->category, self::CASH_CATEGORIES, true)) {
                throw ValidationException::withMessages(['movement' => 'هذه الحركة تُلغى من مصدرها: الكشف أو المصروف أو السلفة.']);
            }

            if (! self::open($movement->created_at)) {
                throw ValidationException::withMessages(['movement' => 'مضت أربعٌ وعشرون ساعة على الحركة: لا تُلغى، صحّحها بحركةٍ جديدة.']);
            }

            $pair = collect([$movement]);

            // المناقلة طرفان: يُلغيان معاً، وإلّا ظهر مالٌ في صندوقٍ ولم يخرج من الآخر
            if (in_array($movement->category, ['transfer_out', 'transfer_in'], true)) {
                $other = CashMovement::query()
                    ->where('cash_box_id', $movement->counterpart_box_id)
                    ->where('counterpart_box_id', $movement->cash_box_id)
                    ->where('category', $movement->category === 'transfer_out' ? 'transfer_in' : 'transfer_out')
                    ->where('amount', $movement->amount)
                    ->where('created_at', $movement->created_at)
                    ->where('reference_type', '!=', 'reversal')
                    ->first();

                if ($other) {
                    $pair->push($other);
                }
            }

            foreach ($pair as $item) {
                if (CashMovement::where('reference_type', 'reversal')->where('reference_id', $item->id)->exists()) {
                    throw ValidationException::withMessages(['movement' => 'أُلغيت هذه الحركة سلفاً.']);
                }
            }

            // الوارد أوّلاً: ما يُعاد إلى صندوقٍ يسبق ما يُسحب من الآخر
            foreach ($pair->sortBy(fn (CashMovement $m) => $m->direction === 'out' ? 0 : 1) as $item) {
                $this->cash->reverse($item, $actor, $reason);
            }
        });
    }

    /** سلفةٌ أُعطيت خطأً، في يومها، ولم يُستردّ منها شيء. */
    public function advance(MerchantAdvance $advance, User $actor, string $reason): MerchantAdvance
    {
        return DB::transaction(function () use ($advance, $actor, $reason) {
            $advance = MerchantAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $this->guardReason($reason);

            if ($advance->status === 'cancelled') {
                throw ValidationException::withMessages(['advance' => "السلفة {$advance->number} ملغاة سلفاً."]);
            }

            if ((int) $advance->recovered > 0) {
                throw ValidationException::withMessages(['advance' => "استُردّ من السلفة {$advance->number} شيء: لا تُلغى، تُسدَّد."]);
            }

            if (! self::open($advance->created_at)) {
                throw ValidationException::withMessages(['advance' => 'مضت أربعٌ وعشرون ساعة على السلفة: لا تُلغى.']);
            }

            $this->reverseAll('merchant_advance', $advance->id, $actor, "إلغاء السلفة {$advance->number} — {$reason}");

            $advance->forceFill([
                'status'               => 'cancelled',
                'cancelled_at'         => now(),
                'cancelled_by_user_id' => $actor->id,
                'cancel_reason'        => $reason,
            ])->save();

            return $advance->refresh();
        });
    }

    protected function guardSettlement(string $status, ?CarbonInterface $confirmedAt, string $code, string $reason): void
    {
        $this->guardReason($reason);

        if ($status === 'draft') {
            throw ValidationException::withMessages(['settlement' => "الكشف {$code} مسودّة: يُحذف من صفحته بلا أثر."]);
        }

        if ($status === 'cancelled') {
            throw ValidationException::withMessages(['settlement' => "الكشف {$code} ملغى سلفاً."]);
        }

        if (! self::open($confirmedAt)) {
            throw ValidationException::withMessages(['settlement' => "مضت أربعٌ وعشرون ساعة على إقفال الكشف {$code}: لا يُحذف ولا يُعدَّل. صحّح بحركةٍ جديدة."]);
        }
    }

    protected function guardReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب الحذف: يبقى في السجلّ.']);
        }
    }

    /**
     * كل ما قيّده المرجع في الدفتر والصندوق، الأحدث أوّلاً. وفي الصندوق يُعكس الخارج قبل
     * الداخل: عمولةٌ تعود إلى الدرج قبل أن يخرج منه ما سلّمه المندوب.
     */
    protected function reverseAll(string $type, int $id, User $actor, string $why): void
    {
        $entries = Transaction::query()->where('reference_type', $type)->where('reference_id', $id)->orderByDesc('id')->get();

        foreach ($entries as $entry) {
            $this->ledger->reverse($entry, $actor, $why);
        }

        $movements = CashMovement::query()->where('reference_type', $type)->where('reference_id', $id)->orderByDesc('id')->get()
            ->sortBy(fn (CashMovement $m) => $m->direction === 'out' ? 0 : 1);

        foreach ($movements as $movement) {
            $this->cash->reverse($movement, $actor, $why);
        }
    }

    /** ما اقتُطع من الكشف لسلف التاجر يعود عليه: السلفة مفتوحةٌ بقدره. */
    protected function restoreAdvances(MerchantSettlement $settlement, User $actor): void
    {
        $recoveries = MerchantAdvanceRecovery::query()
            ->where('merchant_settlement_id', $settlement->id)->where('kind', 'settlement')->get();

        foreach ($recoveries as $recovery) {
            $advance = MerchantAdvance::query()->lockForUpdate()->find($recovery->merchant_advance_id);

            if (! $advance) {
                continue;
            }

            MerchantAdvanceRecovery::create([
                'merchant_advance_id'    => $advance->id,
                'kind'                   => 'reversal',
                'merchant_settlement_id' => $settlement->id,
                'amount'                 => -(int) $recovery->amount,
                'created_by_user_id'     => $actor->id,
                'created_at'             => now(),
            ]);

            $advance->forceFill([
                'recovered' => max(0, (int) $advance->recovered - (int) $recovery->amount),
                'status'    => 'open',
                'repaid_at' => null,
            ])->save();
        }
    }

    protected function freeShipments(string $column, string $stamp, int $settlementId, string $note, User $actor): void
    {
        $ids = Shipment::query()->where($column, $settlementId)->pluck('id');

        Shipment::query()->whereIn('id', $ids->all() ?: [0])->update([$column => null, $stamp => null]);

        foreach ($ids as $shipmentId) {
            ShipmentEvent::create([
                'shipment_id' => $shipmentId,
                'from_status' => null,
                'to_status'   => 'settlement_cancelled',
                'event_type'  => 'money',
                'actor_type'  => 'user',
                'actor_id'    => $actor->id,
                'actor_name'  => $actor->name,
                'note'        => $note.': تعود لكشفٍ جديد',
            ]);
        }
    }

    protected function markCancelled(CourierSettlement|MerchantSettlement $settlement, User $actor, string $reason): void
    {
        $settlement->forceFill([
            'status'               => 'cancelled',
            'cancelled_at'         => now(),
            'cancelled_by_user_id' => $actor->id,
            'cancel_reason'        => $reason,
        ])->save();
    }
}
