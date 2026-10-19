<?php

namespace App\Actions\Profits;

use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchAudit;
use App\Models\CollectionBatchCost;
use App\Models\CollectionBatchItem;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\Expense;
use App\Models\Shipment;
use App\Models\User;
use App\Services\CashBook;
use App\Services\Ledger;
use App\Services\SequenceGenerator;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «وجبات التحصيل» (docs/plan/68 §٧): عملية محاسبةٍ فعلية لمجموعة مناديب، برقمها.
 *
 * طريقتان: من كشوفٍ أُقفلت في «محاسبة المندوبين»، أو «الوجبة أولاً» — تُختار المناديب فتُبنى
 * كشوفهم مسودّات، ويُقفَل كشف كلٍّ منهم من داخل الوجبة حين يُستلم نقده ويُعدّ.
 *
 * الحالات: مسودة ← بانتظار استلام النقد ← بانتظار المطابقة (أو تحتوي على فروقات) ← مطابقة
 * ومعتمدة؛ وقبل الاعتماد ملغاة. والاعتماد يكتب حركات الفرق (ق٤): العجز يخرج من الصندوق إلى
 * ذمّة المندوب أو يُشطب بصلاحية، والزيادة تُعلَّق، وتحصيل عجزٍ سابق يدخل الصندوق.
 *
 * والفصل الذي تقوم عليه كما كان: ما استلمه المندوب منه أموال التجّار، ومنه أجور التوصيل.
 * والاعتماد لا يكتب إيراداً — الإيراد قُيِّد يوم التسليم — بل يحقّق الأجور نقداً.
 */
class ManageCollectionBatch
{
    public function __construct(
        protected SequenceGenerator $sequences,
        protected Ledger $ledger,
        protected CashBook $cash,
        protected BuildCourierSettlement $build,
        protected ConfirmCourierSettlement $confirm,
    ) {}

    /**
     * كشوفٌ مُقفَلة لم تدخل وجبةً بعد — في المدّة (بيوم إقفالها) وللفرع إن اختير.
     *
     * @return Builder<CourierSettlement>
     */
    public static function eligible(?User $viewer, ?int $branchId = null, ?Carbon $from = null, ?Carbon $to = null): Builder
    {
        return CourierSettlement::query()
            ->visibleTo($viewer)
            ->whereIn('courier_settlements.status', ['confirmed', 'paid'])
            ->whereNull('courier_settlements.cancelled_at')
            ->whereNotIn('courier_settlements.id', CollectionBatchItem::query()->whereNotNull('courier_settlement_id')->select('courier_settlement_id'))
            ->when($branchId, fn ($q) => $q->where('courier_settlements.branch_id', $branchId))
            ->when($from, fn ($q) => $q->where('courier_settlements.confirmed_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($q) => $q->where('courier_settlements.confirmed_at', '<=', $to->copy()->endOfDay()));
    }

    /**
     * مناديب بيدهم ما يُحاسَبون عليه: شحناتٌ واصلة لم تدخل كشفاً مُقفَلاً — للوجبة أولاً.
     *
     * @return Builder<Courier>
     */
    public static function couriersToSettle(?User $viewer, ?int $branchId = null): Builder
    {
        return Courier::query()
            ->visibleTo($viewer)
            ->when($branchId, fn ($q) => $q->where('couriers.branch_id', $branchId))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('shipments')
                ->whereColumn('shipments.delivery_courier_id', 'couriers.id')
                ->whereNull('shipments.deleted_at')
                ->whereNull('shipments.courier_settlement_id')
                ->where(fn ($w) => $w->whereNotNull('shipments.delivered_at')
                    ->orWhere(fn ($r) => $r->where('shipments.status', 'returned')->where('shipments.courier_commission', '>', 0))))
            // ومن دخل كشفه المسودّة وجبةً لم تُعتمد لا يُعرض ثانيةً
            ->whereNotIn('couriers.id', CollectionBatchItem::query()
                ->join('collection_batches', 'collection_batches.id', '=', 'collection_batch_items.collection_batch_id')
                ->where('collection_batch_items.status', 'pending_cash')
                ->whereNotIn('collection_batches.status', ['approved', 'cancelled'])
                ->select('collection_batch_items.courier_id'));
    }

    /** @param  list<int>  $settlementIds */
    public function create(array $settlementIds, ?int $branchId, string $period, Carbon $from, Carbon $to, ?string $notes, User $actor, ?int $boxId = null): CollectionBatch
    {
        if ($settlementIds === []) {
            throw ValidationException::withMessages(['settlement_ids' => 'اختر كشوف المناديب التي تدخل الوجبة.']);
        }

        return DB::transaction(function () use ($settlementIds, $branchId, $period, $from, $to, $notes, $actor, $boxId) {
            $batch = $this->newBatch($branchId, $period, $from, $to, $notes, $actor, $boxId);
            $this->attach($batch, $settlementIds, $actor);

            return $this->recalc($batch);
        });
    }

    /**
     * «الوجبة أولاً»: لكل مندوبٍ كشفه المسودّة (أو يُبنى)، ويبقى بانتظار نقده.
     *
     * @param  list<int>  $courierIds
     */
    public function createForCouriers(array $courierIds, ?int $branchId, ?int $boxId, ?string $notes, User $actor): CollectionBatch
    {
        if ($courierIds === []) {
            throw ValidationException::withMessages(['courier_ids' => 'اختر المناديب الذين تحاسبهم في الوجبة.']);
        }

        return DB::transaction(function () use ($courierIds, $branchId, $boxId, $notes, $actor) {
            $batch = $this->newBatch($branchId, 'custom', now(), now(), $notes, $actor, $boxId);
            $couriers = static::couriersToSettle($actor, $branchId)->whereKey($courierIds)->lockForUpdate()->get();

            if ($couriers->count() !== count(array_unique($courierIds))) {
                throw ValidationException::withMessages([
                    'courier_ids' => 'من المناديب المختارين من لا شيء عنده يُحاسَب عليه، أو دخل وجبةً أخرى — أعد فتح الصفحة.',
                ]);
            }

            $names = [];
            foreach ($couriers as $courier) {
                $draft = CourierSettlement::where('courier_id', $courier->id)->where('status', 'draft')
                    ->whereNotIn('id', CollectionBatchItem::query()->whereNotNull('courier_settlement_id')->select('courier_settlement_id'))
                    ->lockForUpdate()->first()
                    ?? $this->build->handle($courier, $actor);

                $this->createItem($batch, $draft, 'pending_cash');
                $names[] = "{$draft->code} ({$courier->name})";
            }

            $this->audit($batch, $actor, 'items_added', implode('، ', $names), null, 'بانتظار النقد');

            return $this->recalc($batch);
        });
    }

    /** @param  list<int>  $settlementIds */
    public function addSettlements(CollectionBatch $batch, array $settlementIds, User $actor): CollectionBatch
    {
        return DB::transaction(function () use ($batch, $settlementIds, $actor) {
            $batch = $this->lockOpen($batch);
            $this->attach($batch, $settlementIds, $actor);

            return $this->recalc($batch);
        });
    }

    public function removeItem(CollectionBatch $batch, CollectionBatchItem $item, User $actor): CollectionBatch
    {
        return DB::transaction(function () use ($batch, $item, $actor) {
            $batch = $this->lockOpen($batch);
            $item = $batch->items()->whereKey($item->id)->firstOrFail();

            if ($item->remainders()->exists()) {
                throw ValidationException::withMessages(['item' => 'سُدِّد عجز هذا الكشف في وجبةٍ أخرى: لا يُخرج.']);
            }

            $this->audit($batch, $actor, 'item_removed', $this->itemName($item), number_format((int) $item->received_cash));
            $item->delete();

            return $this->recalc($batch);
        });
    }

    /**
     * استلام نقد مندوبٍ وعدّه. كشفه المسودّة (الوجبة أولاً) يُقفَل الآن ويدخل نقده صندوق الوجبة،
     * ثم يُكتب ما عُدَّ والفرق. وبعد الاعتماد: تصحيحٌ بسببٍ تُكتب حركته (correct).
     */
    public function receive(CollectionBatch $batch, CollectionBatchItem $item, int $counted, ?string $reason, User $actor): CollectionBatch
    {
        if ($counted < 0) {
            throw ValidationException::withMessages(['received_cash' => 'المستلم فعلاً لا يكون سالباً.']);
        }

        return DB::transaction(function () use ($batch, $item, $counted, $reason, $actor) {
            $batch = $this->lock($batch);

            if ($batch->isCancelled()) {
                throw ValidationException::withMessages(['batch' => "الوجبة {$batch->number} ملغاة."]);
            }

            $item = $batch->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($item->isRemainder()) {
                throw ValidationException::withMessages(['received_cash' => 'تحصيل العجز يُكتب بمبلغه حين يُسجَّل.']);
            }

            if ($batch->isApproved()) {
                return $this->correct($batch, $item, $counted, $reason, $actor);
            }

            $before = (int) $item->received_cash;

            if ($item->status === 'pending_cash') {
                $this->confirmSettlement($batch, $item, $actor);
                $before = null;
            }

            $item->forceFill([
                'received_cash'      => $counted,
                'counted_at'         => now(),
                'counted_by_user_id' => $actor->id,
                'resolution'         => $counted < (int) $item->expected_cash ? $item->resolution : null,
                'resolution_reason'  => $counted < (int) $item->expected_cash ? $item->resolution_reason : null,
            ])->save();

            $this->audit($batch, $actor, 'cash_received', 'عدّ نقد '.$this->itemName($item),
                $before === null ? null : number_format($before), number_format($counted), $reason);

            return $this->recalc($batch);
        });
    }

    /** اسمٌ قديم للاستلام: يبقى لمن يستدعيه */
    public function setReceived(CollectionBatch $batch, CollectionBatchItem $item, int $amount, ?string $reason, User $actor): CollectionBatch
    {
        return $this->receive($batch, $item, $amount, $reason, $actor);
    }

    /**
     * معالجة العجز قبل الاعتماد: ذمّةٌ على المندوب تُسترد لاحقاً، أو شطبٌ بصلاحيةٍ وسبب.
     */
    public function resolve(CollectionBatch $batch, CollectionBatchItem $item, string $resolution, ?string $reason, User $actor): CollectionBatch
    {
        if (! in_array($resolution, ['courier_debt', 'written_off'], true)) {
            throw ValidationException::withMessages(['resolution' => 'اختر: ذمّة على المندوب أو شطب.']);
        }

        if ($resolution === 'written_off' && ! $actor->can('money.write_off')) {
            throw ValidationException::withMessages(['resolution' => 'شطب العجز لصاحب الشركة أو من يُمنح صلاحيته.']);
        }

        if ($resolution === 'written_off' && blank($reason)) {
            throw ValidationException::withMessages(['resolution_reason' => 'اكتب سبب الشطب: يبقى في سجلّ الوجبة.']);
        }

        return DB::transaction(function () use ($batch, $item, $resolution, $reason, $actor) {
            $batch = $this->lockOpen($batch);
            $item = $batch->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($item->discrepancy() >= 0) {
                throw ValidationException::withMessages(['resolution' => 'لا عجز في هذا السطر.']);
            }

            $item->forceFill(['resolution' => $resolution, 'resolution_reason' => $reason])->save();
            $this->audit($batch, $actor, 'resolved', 'عجز '.$this->itemName($item), number_format(-$item->discrepancy()),
                CollectionBatchItem::RESOLUTIONS[$resolution], $reason);

            return $this->recalc($batch);
        });
    }

    /** @param  list<int>  $expenseIds */
    public function addCosts(CollectionBatch $batch, array $expenseIds, ?string $reason, User $actor): CollectionBatch
    {
        return DB::transaction(function () use ($batch, $expenseIds, $reason, $actor) {
            $batch = $this->lockNotCancelled($batch);
            $this->requireReason($batch, $reason);

            $expenses = Expense::query()->visibleTo($actor)->whereKey($expenseIds)
                ->where('status', '!=', 'cancelled')
                ->when($batch->branch_id, fn ($q) => $q->where('branch_id', $batch->branch_id))
                ->lockForUpdate()->get();

            foreach ($expenses as $expense) {
                try {
                    // الفهرس الفريد يمنع حساب المصروف في وجبتين ولو تزامنتا
                    DB::transaction(fn () => CollectionBatchCost::create([
                        'collection_batch_id' => $batch->id,
                        'expense_id'          => $expense->id,
                        'amount'              => (int) $expense->amount,
                    ]));
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages(['expense_ids' => "المصروف {$expense->number} محسوبٌ على وجبةٍ أخرى."]);
                }

                $this->audit($batch, $actor, $batch->isApproved() ? 'corrected' : 'cost_added',
                    "مصروف {$expense->number} — {$expense->description}", null, number_format((int) $expense->amount), $reason);
            }

            return $this->recalc($batch);
        });
    }

    public function removeCost(CollectionBatch $batch, CollectionBatchCost $cost, ?string $reason, User $actor): CollectionBatch
    {
        return DB::transaction(function () use ($batch, $cost, $reason, $actor) {
            $batch = $this->lockNotCancelled($batch);
            $cost = $batch->costLines()->with('expense')->whereKey($cost->id)->firstOrFail();
            $this->requireReason($batch, $reason);

            $this->audit($batch, $actor, $batch->isApproved() ? 'corrected' : 'cost_removed',
                'مصروف '.($cost->expense?->number ?? '#'.$cost->expense_id).' أُخرج', number_format((int) $cost->amount), null, $reason);
            $cost->delete();

            return $this->recalc($batch);
        });
    }

    /**
     * تحصيلٌ لعجزٍ سابق: المندوب سلّم ما نقص في وجبةٍ معتمدة. يُربط بسطر العجز نفسه
     * فلا يُحصَّل الدينار مرّتين، ولا يزيد على ما بقي منه. ونقده يدخل الصندوق عند الاعتماد.
     */
    public function addRemainder(CollectionBatch $batch, CollectionBatchItem $original, int $amount, string $note, User $actor): CollectionBatch
    {
        return DB::transaction(function () use ($batch, $original, $amount, $note, $actor) {
            $batch = $this->lockOpen($batch);
            $original = CollectionBatchItem::query()->with('batch')->lockForUpdate()->findOrFail($original->id);

            if ($original->isRemainder() || ! $original->batch?->isApproved() || $original->collection_batch_id === $batch->id) {
                throw ValidationException::withMessages(['remainder_of_item_id' => 'التحصيل يكون لعجز كشفٍ في وجبةٍ سابقة معتمدة.']);
            }

            $outstanding = $original->outstanding();

            if ($amount < 1 || $amount > $outstanding) {
                throw ValidationException::withMessages([
                    'amount' => 'الباقي من عجز هذا الكشف '.number_format($outstanding).' د.ع: التحصيل بين ١ وهذا المبلغ.',
                ]);
            }

            $batch->items()->create([
                'kind'                 => 'remainder',
                'status'               => 'received',
                'courier_id'           => $original->courier_id,
                'remainder_of_item_id' => $original->id,
                'received_cash'        => $amount,
                'note'                 => $note,
                'counted_at'           => now(),
                'counted_by_user_id'   => $actor->id,
            ]);

            $this->audit($batch, $actor, 'remainder_added',
                'عجز '.$this->itemName($original).' من '.$original->batch->number, number_format($outstanding), number_format($amount), $note);

            return $this->recalc($batch);
        });
    }

    /**
     * الاعتماد بعد المطابقة: لا نقد ينتظر، وكل عجزٍ معالَج — أو استثناءٌ موثّق بصلاحية الشطب،
     * فيصير العجز غير المعالَج ذمّةً على مندوبه. ثم تُكتب حركات الفروق وتتحقّق الأجور نقداً.
     */
    public function approve(CollectionBatch $batch, User $actor, ?string $exception = null): CollectionBatch
    {
        return DB::transaction(function () use ($batch, $actor, $exception) {
            $batch = $this->lockOpen($batch);
            $items = $batch->items()->with(['courier', 'remainderOf'])->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['batch' => 'الوجبة فارغة: أضف كشفاً واحداً على الأقل قبل اعتمادها.']);
            }

            if ($pending = $items->where('status', 'pending_cash')->count()) {
                throw ValidationException::withMessages(['batch' => "نقد {$pending} من المناديب لم يُستلم بعد: استلمه وعدّه، أو أخرجهم من الوجبة."]);
            }

            if ($this->dualApproval() && (int) $batch->created_by_user_id === (int) $actor->id) {
                throw ValidationException::withMessages(['batch' => 'يعتمد الوجبة غيرُ من أنشأها (إعداد الشركة).']);
            }

            $unresolved = $items->filter(fn (CollectionBatchItem $i) => ! $i->isRemainder() && $i->discrepancy() < 0 && $i->resolution === null);

            if ($unresolved->isNotEmpty()) {
                if (blank($exception) || ! $actor->can('money.write_off')) {
                    throw ValidationException::withMessages(['batch' => 'عجزٌ لم يُعالَج عند '.$unresolved->count()
                        .' من المناديب: اختر لكلٍّ ذمّةً عليه أو شطباً، أو اعتمد باستثناءٍ موثّق (صاحب الشركة).']);
                }

                foreach ($unresolved as $item) {
                    $item->forceFill(['resolution' => 'courier_debt', 'resolution_reason' => $exception])->save();
                }
            }

            foreach ($items as $item) {
                $item->isRemainder() ? $this->postRemainder($batch, $item, $actor) : $this->postItem($batch, $item, $actor);
            }

            $batch = $this->recalc($batch);
            $batch->forceFill([
                'status'              => 'approved',
                'approved_at'         => now(),
                'approved_by_user_id' => $actor->id,
                'realized_fees'       => (int) $batch->items()->sum('realized_fees'),
            ])->save();

            $this->audit($batch, $actor, $exception && $unresolved->isNotEmpty() ? 'approved_exception' : 'approved',
                $batch->reconciliationLabel(), null, number_format((int) $batch->net_profit), $exception);

            return $batch;
        });
    }

    /** إلغاء وجبةٍ لم تُعتمد: كشوفها ومصروفاتها تعود لوجبةٍ أخرى، والوجبة باقيةٌ بسجلّها */
    public function cancel(CollectionBatch $batch, string $reason, User $actor): CollectionBatch
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب الإلغاء: يبقى في سجلّ الوجبة.']);
        }

        return DB::transaction(function () use ($batch, $reason, $actor) {
            $batch = $this->lockOpen($batch);

            $this->audit($batch, $actor, 'cancelled', $batch->number, number_format((int) $batch->received_cash), null, $reason);

            $batch->costLines()->delete();
            $batch->items()->delete();
            $batch = $this->recalc($batch);
            $batch->forceFill([
                'status'               => 'cancelled',
                'cancelled_at'         => now(),
                'cancelled_by_user_id' => $actor->id,
                'cancel_reason'        => $reason,
            ])->save();

            return $batch;
        });
    }

    /** اسمٌ قديم: الوجبة لا تُحذف، تُلغى بسجلّها */
    public function delete(CollectionBatch $batch, User $actor): void
    {
        $this->cancel($batch, 'حُذفت المسودّة', $actor);
    }

    /**
     * أرقام الوجبة وحالها من سطورها: تُعاد بعد كل تغيير، فلا رقمٌ يُكتب باليد.
     */
    public function recalc(CollectionBatch $batch): CollectionBatch
    {
        $items = CollectionBatchItem::where('collection_batch_id', $batch->id)->get();

        // حال كل سطرٍ من فرقه — والذي ينتظر نقده يبقى كما هو
        foreach ($items as $item) {
            if (! $item->isRemainder() && $item->status !== 'pending_cash') {
                $status = $item->discrepancy() < 0 ? 'short' : ($item->discrepancy() > 0 ? 'over' : 'received');
                if ($item->status !== $status) {
                    $item->forceFill(['status' => $status])->save();
                }
            }
        }

        $settlements = $items->where('kind', 'settlement');
        $counted = $settlements->where('status', '!=', 'pending_cash');
        $remainders = $items->where('kind', 'remainder');
        $costs = (int) CollectionBatchCost::where('collection_batch_id', $batch->id)->sum('amount');

        $sum = fn (string $column) => (int) $settlements->sum($column);
        $received = (int) $counted->sum('received_cash') + (int) $remainders->sum('received_cash');

        $status = $batch->status;
        if (! in_array($status, ['approved', 'cancelled'], true)) {
            $status = match (true) {
                $items->isEmpty()                                     => 'draft',
                $settlements->contains('status', 'pending_cash')      => 'awaiting_cash',
                $settlements->contains(fn ($i) => $i->status === 'short' && $i->resolution === null) => 'discrepancy',
                default                                               => 'awaiting_reconciliation',
            };
        }

        $batch->forceFill([
            'status'              => $status,
            'couriers_count'      => $settlements->pluck('courier_id')->filter()->unique()->count(),
            'settlements_count'   => $settlements->count(),
            'shipments_count'     => $sum('shipments_count'),
            'delivered_count'     => $sum('delivered_count'),
            'cod_collected'       => $sum('cod_collected'),
            'merchant_money'      => $sum('merchant_money'),
            'delivery_fees'       => $sum('delivery_fees'),
            'fees_in_cash'        => max(0, $sum('cod_collected') - $sum('merchant_money')),
            'courier_commissions' => $sum('courier_commissions'),
            'deductions'          => $sum('deductions'),
            'costs'               => $costs,
            'expected_cash'       => $sum('expected_cash'),
            'received_cash'       => $received,
            'discrepancy'         => (int) $counted->sum('received_cash') - (int) $counted->sum('expected_cash'),
            'remainders'          => (int) $remainders->sum('received_cash'),
            'net_profit'          => $sum('delivery_fees') - $sum('courier_commissions') - $costs,
            'realized_profit'     => $received - $sum('merchant_money') - $sum('deductions') - $costs,
        ])->save();

        return $batch;
    }

    protected function newBatch(?int $branchId, string $period, Carbon $from, Carbon $to, ?string $notes, User $actor, ?int $boxId): CollectionBatch
    {
        $box = $boxId ? CashBox::payableBy($actor)->find($boxId) : null;

        if ($boxId && ! $box) {
            throw ValidationException::withMessages(['cash_box_id' => 'اختر صندوقاً تقبض فيه: صندوقك أو صندوق فرعك.']);
        }

        $batch = CollectionBatch::create([
            'number'             => $this->sequences->next('collection_batch'),
            'branch_id'          => $branchId,
            'cash_box_id'        => $box?->id,
            'period'             => array_key_exists($period, CollectionBatch::PERIODS) ? $period : 'custom',
            'from_date'          => $from->toDateString(),
            'to_date'            => $to->toDateString(),
            'notes'              => $notes,
            'created_by_user_id' => $actor->id,
        ]);

        $this->audit($batch, $actor, 'created', $batch->number);

        return $batch;
    }

    /** @param  list<int>  $settlementIds */
    protected function attach(CollectionBatch $batch, array $settlementIds, User $actor): void
    {
        $settlements = static::eligible($actor, $batch->branch_id)
            ->whereKey($settlementIds)
            ->with('courier:id,name')
            ->lockForUpdate()
            ->get();

        if ($settlements->count() !== count(array_unique($settlementIds))) {
            throw ValidationException::withMessages([
                'settlement_ids' => 'من الكشوف المختارة ما دخل وجبةً أخرى أو أُلغي أو ليس لهذا الفرع — أعد فتح الصفحة واختر من جديد.',
            ]);
        }

        foreach ($settlements as $settlement) {
            $this->createItem($batch, $settlement, 'received');
        }

        $this->audit($batch, $actor, 'items_added', $settlements->map(fn ($s) => $s->code.' ('.($s->courier?->name ?? '—').')')->implode('، '),
            null, number_format((int) $settlements->sum('net_amount')));
    }

    protected function createItem(CollectionBatch $batch, CourierSettlement $settlement, string $status): CollectionBatchItem
    {
        try {
            // الفهرس الفريد على الكشف: وجبتان متزامنتان لا تحسبانه معاً
            return DB::transaction(fn () => $batch->items()->create([
                'kind'                  => 'settlement',
                'status'                => $status,
                'courier_id'            => $settlement->courier_id,
                'courier_settlement_id' => $settlement->id,
                'received_cash'         => $status === 'pending_cash' ? 0 : (int) $settlement->net_amount,
            ] + $this->figuresOf($settlement)));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['settlement_ids' => "الكشف {$settlement->code} في وجبةٍ أخرى."]);
        }
    }

    /** أرقام السطر من كشفه وشحناته: ما حُصِّل، وما منه للتجّار، وأجور التوصيل كما تحسبها الشركة */
    protected function figuresOf(CourierSettlement $settlement): array
    {
        $f = DB::table('courier_settlement_shipments as l')
            ->join('shipments', 'shipments.id', '=', 'l.shipment_id')
            ->where('l.courier_settlement_id', $settlement->id)
            ->selectRaw('count(*) as shipments,
                sum(case when '.Shipment::sqlDelivered().' then 1 else 0 end) as delivered,
                sum(l.collected_amount) as collected,
                sum('.Shipment::sqlRevenue().') as fees,
                sum(case when '.Shipment::sqlDelivered().' and shipments.merchant_due > 0 then shipments.merchant_due else 0 end) as merchant')
            ->first();

        return [
            'shipments_count'     => (int) ($f->shipments ?? $settlement->shipments_count),
            'delivered_count'     => (int) ($f->delivered ?? 0),
            'cod_collected'       => (int) ($f->collected ?? $settlement->cod_total),
            'merchant_money'      => (int) ($f->merchant ?? 0),
            'delivery_fees'       => (int) ($f->fees ?? 0),
            'courier_commissions' => (int) $settlement->commission_total,
            'deductions'          => (int) $settlement->deductions,
            'expected_cash'       => (int) $settlement->net_amount,
        ];
    }

    /** كشف «الوجبة أولاً» يُقفَل لحظة استلام نقده، ونقده يدخل صندوق الوجبة */
    protected function confirmSettlement(CollectionBatch $batch, CollectionBatchItem $item, User $actor): void
    {
        $settlement = CourierSettlement::query()->lockForUpdate()->findOrFail($item->courier_settlement_id);

        if ($settlement->status === 'draft') {
            $settlement = $this->confirm->handle($settlement, $actor, box: $batch->cash_box_id ? CashBox::find($batch->cash_box_id) : null);
        }

        $item->forceFill(['status' => 'received'] + $this->figuresOf($settlement))->save();
    }

    /**
     * حركات فرق السطر عند الاعتماد (ق٤): العجز من الصندوق إلى ذمّة المندوب — ومشطوبه خسارة —
     * والزيادة تُعلَّق. وتتحقّق أجور السطر نقداً ناقصاً عجزه.
     */
    protected function postItem(CollectionBatch $batch, CollectionBatchItem $item, User $actor): void
    {
        $diff = $item->discrepancy();
        $this->moveDifference($batch, $item, 0, $diff, $actor);

        $feesInCash = max(0, (int) $item->cod_collected - (int) $item->merchant_money);
        $item->forceFill([
            'posted_at'     => now(),
            'realized_fees' => max(0, $feesInCash - max(0, -$diff)),
        ])->save();
    }

    /** تحصيل عجزٍ سابق: ذمّة المندوب تنقص، ونقده يدخل الصندوق — ويتحقّق ما كان العجز أخّره */
    protected function postRemainder(CollectionBatch $batch, CollectionBatchItem $item, User $actor): void
    {
        $original = $item->remainderOf;
        $amount = (int) $item->received_cash;

        // عجزٌ وُثِّق قبل الإصدار الثاني لم يُكتب على المندوب: تحصيله توثيقٌ كذلك
        if ($original?->posted_at !== null && $original->resolution === 'courier_debt' && $item->courier) {
            $this->ledger->recordShortage($item->courier, 'shortage_recovered', 'credit', $amount,
                "تحصيل عجز {$this->itemName($original)} — وجبة {$batch->number}", $batch->id, $actor);

            if ($box = $this->batchBox($batch, $actor)) {
                $this->cash->in($box, 'shortage_recovered', $amount, "تحصيل عجز {$item->courier->name} — وجبة {$batch->number}",
                    $actor, 'collection_batch', $batch->id);
            }
        }

        $item->forceFill(['posted_at' => now(), 'realized_fees' => $amount])->save();
    }

    /**
     * تصحيح المعدود بعد الاعتماد: بسببٍ مكتوب، وتُكتب حركة الفرق بين الفرقين لا الفرق كلّه.
     */
    protected function correct(CollectionBatch $batch, CollectionBatchItem $item, int $counted, ?string $reason, User $actor): CollectionBatch
    {
        $this->requireReason($batch, $reason);

        if ($item->resolution === 'written_off') {
            throw ValidationException::withMessages(['received_cash' => 'عجز هذا الكشف مشطوب: لا يُصحَّح، سجّل تحصيلاً في وجبةٍ جديدة إن سُدِّد.']);
        }

        $before = (int) $item->received_cash;

        if ($before === $counted) {
            return $batch;
        }

        // عجزٌ سُدِّد منه شيءٌ في وجبةٍ لاحقة لا يصغر تحت ما سُدِّد
        $paid = (int) $item->remainders()->sum('received_cash');
        if ($paid > 0 && $counted + $paid > (int) $item->expected_cash) {
            throw ValidationException::withMessages([
                'received_cash' => 'سُدِّد من عجز هذا الكشف '.number_format($paid).' د.ع في وجبةٍ لاحقة: المستلم هنا لا يزيد على '
                    .number_format((int) $item->expected_cash - $paid).' د.ع.',
            ]);
        }

        $oldDiff = $item->discrepancy();
        $item->forceFill([
            'received_cash' => $counted,
            'resolution'    => $counted < (int) $item->expected_cash ? ($item->resolution ?? 'courier_debt') : null,
        ])->save();

        // الوجبات المعتمدة قبل الإصدار الثاني وثّقت فرقها بلا حركة: تصحيحها توثيقٌ كذلك
        if ($item->posted_at !== null) {
            $this->moveDifference($batch, $item, $oldDiff, $item->discrepancy(), $actor);
            $feesInCash = max(0, (int) $item->cod_collected - (int) $item->merchant_money);
            $item->forceFill(['realized_fees' => max(0, $feesInCash - max(0, -$item->discrepancy()))])->save();
        }

        $this->audit($batch, $actor, 'corrected', 'المستلم فعلاً — '.$this->itemName($item), number_format($before), number_format($counted), $reason);

        $batch = $this->recalc($batch);
        $batch->forceFill(['realized_fees' => (int) $batch->items()->sum('realized_fees')])->save();

        return $batch;
    }

    /**
     * يكتب ما تغيّر بين فرقين: العجز (سالب الفرق) ذمّةً على المندوب يخرج نقدها من الصندوق،
     * والزيادة (موجبه) نقدٌ يدخل الصندوق معلَّقاً. من صفرٍ عند الاعتماد، ومن فرقٍ إلى فرق عند التصحيح.
     */
    protected function moveDifference(CollectionBatch $batch, CollectionBatchItem $item, int $from, int $to, User $actor): void
    {
        $courier = $item->courier ?? Courier::withTrashed()->find($item->courier_id);
        $box = $this->settlementBox($item) ?? $this->batchBox($batch, $actor);
        $name = $this->itemName($item);

        $shortage = max(0, -$to) - max(0, -$from);
        if ($shortage !== 0 && $courier) {
            $amount = abs($shortage);
            $more = $shortage > 0;

            $this->ledger->recordShortage($courier, 'shortage', $more ? 'debit' : 'credit', $amount,
                ($more ? 'عجز ' : 'تصحيح عجز ')."{$name} — وجبة {$batch->number}", $batch->id, $actor);

            if ($box) {
                // نقدٌ قبضه الصندوق بالكشف ولم يصله: يخرج منه ولو كان رصيده أقلّ — هو تصحيحٌ لما لم يدخل
                $more
                    ? $this->cash->out($box, 'collection_shortage', $amount, "عجز {$name} — وجبة {$batch->number}", $actor, 'collection_batch', $batch->id, coveredBy: $amount)
                    : $this->cash->in($box, 'collection_shortage', $amount, "تصحيح عجز {$name} — وجبة {$batch->number}", $actor, 'collection_batch', $batch->id);
            }

            if ($item->resolution === 'written_off') {
                $this->ledger->recordShortage($courier, 'shortage_written_off', $more ? 'credit' : 'debit', $amount,
                    "شطب عجز {$name} — وجبة {$batch->number}".($item->resolution_reason ? " — {$item->resolution_reason}" : ''), $batch->id, $actor);
            }
        }

        $excess = max(0, $to) - max(0, $from);
        if ($excess !== 0 && $box) {
            $excess > 0
                ? $this->cash->in($box, 'collection_excess', $excess, "زيادة {$name} — وجبة {$batch->number}", $actor, 'collection_batch', $batch->id)
                : $this->cash->out($box, 'collection_excess', -$excess, "تصحيح زيادة {$name} — وجبة {$batch->number}", $actor, 'collection_batch', $batch->id, coveredBy: -$excess);
        }
    }

    /** الصندوق الذي قبض نقد الكشف عند إقفاله */
    protected function settlementBox(CollectionBatchItem $item): ?CashBox
    {
        $boxId = $item->courier_settlement_id ? CashMovement::where('reference_type', 'courier_settlement')
            ->where('reference_id', $item->courier_settlement_id)->where('category', 'courier_handover')
            ->orderBy('id')->value('cash_box_id') : null;

        return $boxId ? CashBox::find($boxId) : null;
    }

    protected function batchBox(CollectionBatch $batch, User $actor): ?CashBox
    {
        return ($batch->cash_box_id ? CashBox::find($batch->cash_box_id) : null)
            ?? CashBox::forActor($actor, $batch->branch_id);
    }

    protected function dualApproval(): bool
    {
        return (bool) Tenancy::company()?->setting('finance.dual_approval', false);
    }

    protected function lock(CollectionBatch $batch): CollectionBatch
    {
        return CollectionBatch::query()->lockForUpdate()->findOrFail($batch->id);
    }

    protected function lockNotCancelled(CollectionBatch $batch): CollectionBatch
    {
        $batch = $this->lock($batch);

        if ($batch->isCancelled()) {
            throw ValidationException::withMessages(['batch' => "الوجبة {$batch->number} ملغاة."]);
        }

        return $batch;
    }

    /** وجبةٌ تُعدَّل: لم تُعتمد ولم تُلغَ */
    protected function lockOpen(CollectionBatch $batch): CollectionBatch
    {
        $batch = $this->lockNotCancelled($batch);

        if ($batch->isApproved()) {
            throw ValidationException::withMessages([
                'batch' => "الوجبة {$batch->number} معتمدة: لا تُضاف إليها كشوف ولا تُلغى — التصحيح بسببٍ يُكتب في سجلّها.",
            ]);
        }

        return $batch;
    }

    protected function requireReason(CollectionBatch $batch, ?string $reason): void
    {
        if ($batch->isApproved() && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'الوجبة معتمدة: اكتب سبب التصحيح، يبقى في سجلّها.']);
        }
    }

    protected function itemName(CollectionBatchItem $item): string
    {
        $item->loadMissing('courier:id,name', 'settlement:id,code');

        return trim(($item->settlement?->code ?? 'تحصيل').' — '.($item->courier?->name ?? ''), ' —');
    }

    protected function audit(CollectionBatch $batch, User $actor, string $action, ?string $subject, ?string $before = null, ?string $after = null, ?string $reason = null): void
    {
        CollectionBatchAudit::create([
            'collection_batch_id' => $batch->id,
            'user_id'             => $actor->id,
            'user_name'           => $actor->name,
            'action'              => $action,
            'subject'             => mb_substr((string) $subject, 0, 160),
            'before'              => $before,
            'after'               => $after,
            'reason'              => $reason,
        ]);
    }
}
