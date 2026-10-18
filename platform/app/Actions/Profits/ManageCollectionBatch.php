<?php

namespace App\Actions\Profits;

use App\Models\CollectionBatch;
use App\Models\CollectionBatchAudit;
use App\Models\CollectionBatchCost;
use App\Models\CollectionBatchItem;
use App\Models\CourierSettlement;
use App\Models\Expense;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SequenceGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «وجبات التحصيل والأرباح»: بناء الوجبة من كشوف المناديب المُقفَلة، وتعديلها مسودّةً،
 * واعتمادها، وتصحيحها بعده بسببٍ مكتوب.
 *
 * الفصل الذي تقوم عليه: ما استلمه المندوب من الزبائن (cod_collected) منه أموال التجّار
 * (merchant_money) وليست لنا، ومنه أجور التوصيل — إيراد الشركة (delivery_fees). والربح
 * المحاسبيّ الأجور ناقص عمولات المناديب والتكاليف، والمتحقّق نقداً ما بقي في اليد للشركة
 * ممّا عُدَّ فعلاً بعد أموال التجّار وخصومات التلف والتكاليف. والفرق بينهما أجورٌ تُستوفى
 * من حساب التاجر (راجعٌ أو أجورٌ على التاجر) أو دُفعت مقدّماً، وعجزٌ لم يُسدَّد.
 *
 * والفرق بين المعدود والمتوقَّع يُوثَّق هنا ولا يُحرّك الصندوق: الصندوق قبض ما في الكشف
 * لحظة إقفاله (ConfirmCourierSettlement)، وجرده يُسوّى من «الصندوق».
 */
class ManageCollectionBatch
{
    public function __construct(protected SequenceGenerator $sequences) {}

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
     * @param  list<int>  $settlementIds
     */
    public function create(array $settlementIds, ?int $branchId, string $period, Carbon $from, Carbon $to, ?string $notes, User $actor): CollectionBatch
    {
        if ($settlementIds === []) {
            throw ValidationException::withMessages(['settlement_ids' => 'اختر كشوف المناديب التي تدخل الوجبة.']);
        }

        return DB::transaction(function () use ($settlementIds, $branchId, $period, $from, $to, $notes, $actor) {
            $batch = CollectionBatch::create([
                'number'             => $this->sequences->next('collection_batch'),
                'branch_id'          => $branchId,
                'period'             => array_key_exists($period, CollectionBatch::PERIODS) ? $period : 'custom',
                'from_date'          => $from->toDateString(),
                'to_date'            => $to->toDateString(),
                'notes'              => $notes,
                'created_by_user_id' => $actor->id,
            ]);

            $this->audit($batch, $actor, 'created', $batch->number);
            $this->attach($batch, $settlementIds, $actor);

            return $this->recalc($batch);
        });
    }

    /** @param  list<int>  $settlementIds */
    public function addSettlements(CollectionBatch $batch, array $settlementIds, User $actor): CollectionBatch
    {
        return DB::transaction(function () use ($batch, $settlementIds, $actor) {
            $batch = $this->lockDraft($batch);
            $this->attach($batch, $settlementIds, $actor);

            return $this->recalc($batch);
        });
    }

    public function removeItem(CollectionBatch $batch, CollectionBatchItem $item, User $actor): CollectionBatch
    {
        return DB::transaction(function () use ($batch, $item, $actor) {
            $batch = $this->lockDraft($batch);
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
     * ما عُدَّ فعلاً من المندوب. في المسودّة يُكتب كما هو، وبعد الاعتماد تصحيحٌ بسببه.
     */
    public function setReceived(CollectionBatch $batch, CollectionBatchItem $item, int $amount, ?string $reason, User $actor): CollectionBatch
    {
        if ($amount < 0) {
            throw ValidationException::withMessages(['received_cash' => 'المستلم فعلاً لا يكون سالباً.']);
        }

        return DB::transaction(function () use ($batch, $item, $amount, $reason, $actor) {
            $batch = $this->lock($batch);
            $item = $batch->items()->whereKey($item->id)->firstOrFail();
            $this->requireReason($batch, $reason);

            if ($item->isRemainder()) {
                throw ValidationException::withMessages(['received_cash' => 'تحصيل العجز يُكتب بمبلغه حين يُسجَّل.']);
            }

            $before = (int) $item->received_cash;

            if ($before === $amount) {
                return $batch;
            }

            // عجزٌ سُدِّد منه شيءٌ في وجبةٍ لاحقة لا يصغر تحت ما سُدِّد
            $paid = (int) $item->remainders()->sum('received_cash');
            if ($paid > 0 && $amount + $paid > (int) $item->expected_cash) {
                throw ValidationException::withMessages([
                    'received_cash' => 'سُدِّد من عجز هذا الكشف '.number_format($paid).' د.ع في وجبةٍ لاحقة: المستلم هنا لا يزيد على '
                        .number_format((int) $item->expected_cash - $paid).' د.ع.',
                ]);
            }

            $item->forceFill(['received_cash' => $amount])->save();
            $this->audit($batch, $actor, $batch->isApproved() ? 'corrected' : 'received_changed',
                'المستلم فعلاً — '.$this->itemName($item), number_format($before), number_format($amount), $reason);

            return $this->recalc($batch);
        });
    }

    /** @param  list<int>  $expenseIds */
    public function addCosts(CollectionBatch $batch, array $expenseIds, ?string $reason, User $actor): CollectionBatch
    {
        return DB::transaction(function () use ($batch, $expenseIds, $reason, $actor) {
            $batch = $this->lock($batch);
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
            $batch = $this->lock($batch);
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
     * فلا يُحصَّل الدينار مرّتين، ولا يزيد على ما بقي منه.
     */
    public function addRemainder(CollectionBatch $batch, CollectionBatchItem $original, int $amount, string $note, User $actor): CollectionBatch
    {
        return DB::transaction(function () use ($batch, $original, $amount, $note, $actor) {
            $batch = $this->lockDraft($batch);
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
                'courier_id'           => $original->courier_id,
                'remainder_of_item_id' => $original->id,
                'received_cash'        => $amount,
                'note'                 => $note,
            ]);

            $this->audit($batch, $actor, 'remainder_added',
                'عجز '.$this->itemName($original).' من '.$original->batch->number, number_format($outstanding), number_format($amount), $note);

            return $this->recalc($batch);
        });
    }

    public function approve(CollectionBatch $batch, User $actor): CollectionBatch
    {
        return DB::transaction(function () use ($batch, $actor) {
            $batch = $this->lockDraft($batch);

            if (! $batch->items()->exists()) {
                throw ValidationException::withMessages(['batch' => 'الوجبة فارغة: أضف كشفاً واحداً على الأقل قبل اعتمادها.']);
            }

            $batch = $this->recalc($batch);
            $batch->forceFill([
                'status'              => 'approved',
                'approved_at'         => now(),
                'approved_by_user_id' => $actor->id,
            ])->save();

            $this->audit($batch, $actor, 'approved', $batch->reconciliationLabel(), null, number_format((int) $batch->net_profit));

            return $batch;
        });
    }

    /** المسودّة تُحذف ولا أثر لها في المال؛ كشوفها ومصروفاتها تعود لوجبةٍ أخرى */
    public function delete(CollectionBatch $batch, User $actor): void
    {
        DB::transaction(function () use ($batch, $actor) {
            $batch = $this->lockDraft($batch);

            \App\Models\AuditLog::create([
                'user_id'        => $actor->id,
                'user_name'      => $actor->name,
                'action'         => 'collection_batch_deleted',
                'auditable_type' => CollectionBatch::class,
                'auditable_id'   => $batch->id,
                'old_values'     => [
                    'number'      => $batch->number,
                    'settlements' => (int) $batch->settlements_count,
                    'net_profit'  => (int) $batch->net_profit,
                ],
                'ip'             => request()->ip(),
            ]);

            $batch->costLines()->delete();
            $batch->items()->delete();
            $batch->audits()->getQuery()->toBase()->delete();
            $batch->delete();
        });
    }

    /**
     * أرقام الوجبة من سطورها: تُعاد حسابها بعد كل تغيير، فلا رقمٌ يُكتب باليد.
     */
    public function recalc(CollectionBatch $batch): CollectionBatch
    {
        $items = CollectionBatchItem::where('collection_batch_id', $batch->id)->get();
        $settlements = $items->where('kind', 'settlement');
        $remainders = $items->where('kind', 'remainder');
        $costs = (int) CollectionBatchCost::where('collection_batch_id', $batch->id)->sum('amount');

        $sum = fn (string $column) => (int) $settlements->sum($column);
        $received = $sum('received_cash') + (int) $remainders->sum('received_cash');

        $batch->forceFill([
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
            'discrepancy'         => $sum('received_cash') - $sum('expected_cash'),
            'remainders'          => (int) $remainders->sum('received_cash'),
            'net_profit'          => $sum('delivery_fees') - $sum('courier_commissions') - $costs,
            'realized_profit'     => $received - $sum('merchant_money') - $sum('deductions') - $costs,
        ])->save();

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

        $figures = $this->figures($settlements->pluck('id')->all());

        foreach ($settlements as $settlement) {
            $f = $figures[$settlement->id] ?? null;

            try {
                // الفهرس الفريد على الكشف: وجبتان متزامنتان لا تحسبانه معاً
                DB::transaction(fn () => $batch->items()->create([
                    'kind'                  => 'settlement',
                    'courier_id'            => $settlement->courier_id,
                    'courier_settlement_id' => $settlement->id,
                    'shipments_count'       => (int) ($f->shipments ?? $settlement->shipments_count),
                    'delivered_count'       => (int) ($f->delivered ?? 0),
                    'cod_collected'         => (int) ($f->collected ?? $settlement->cod_total),
                    'merchant_money'        => (int) ($f->merchant ?? 0),
                    'delivery_fees'         => (int) ($f->fees ?? 0),
                    'courier_commissions'   => (int) $settlement->commission_total,
                    'deductions'            => (int) $settlement->deductions,
                    'expected_cash'         => (int) $settlement->net_amount,
                    'received_cash'         => (int) $settlement->net_amount,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['settlement_ids' => "الكشف {$settlement->code} في وجبةٍ أخرى."]);
            }
        }

        $this->audit($batch, $actor, 'items_added', $settlements->map(fn ($s) => $s->code.' ('.($s->courier?->name ?? '—').')')->implode('، '),
            null, number_format((int) $settlements->sum('net_amount')));
    }

    /**
     * أرقام كل كشفٍ من شحناته: ما حُصِّل، وما منه للتجّار، وأجور التوصيل كما تحسبها الشركة.
     *
     * @param  list<int>  $settlementIds
     * @return array<int, object>
     */
    protected function figures(array $settlementIds): array
    {
        return DB::table('courier_settlement_shipments as l')
            ->join('shipments', 'shipments.id', '=', 'l.shipment_id')
            ->whereIn('l.courier_settlement_id', $settlementIds ?: [0])
            ->selectRaw('l.courier_settlement_id as id, count(*) as shipments,
                sum(case when '.Shipment::sqlDelivered().' then 1 else 0 end) as delivered,
                sum(l.collected_amount) as collected,
                sum('.Shipment::sqlRevenue().') as fees,
                sum(case when '.Shipment::sqlDelivered().' and shipments.merchant_due > 0 then shipments.merchant_due else 0 end) as merchant')
            ->groupBy('l.courier_settlement_id')
            ->get()
            ->keyBy('id')
            ->all();
    }

    protected function lock(CollectionBatch $batch): CollectionBatch
    {
        return CollectionBatch::query()->lockForUpdate()->findOrFail($batch->id);
    }

    protected function lockDraft(CollectionBatch $batch): CollectionBatch
    {
        $batch = $this->lock($batch);

        if ($batch->isApproved()) {
            throw ValidationException::withMessages([
                'batch' => "الوجبة {$batch->number} معتمدة: لا تُضاف إليها كشوف ولا تُحذف — التصحيح بسببٍ يُكتب في سجلّها.",
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
