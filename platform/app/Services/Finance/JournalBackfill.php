<?php

namespace App\Services\Finance;

use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ProfitWithdrawal;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * بناء دفتر القيود من التاريخ بالقواعد نفسها التي تكتبه حيّاً (PostingRules).
 *
 * شركةٌ لها حركاتٌ قبل الدفتر تبدأ «غير مهيّأة»: لا يُكتب لها قيدٌ حيّ حتى يُبنى تاريخها
 * هنا بترتيبه، ثم تُعلَّم مهيّأة، ثم تُلتقط الحركات التي جاءت أثناء البناء. وكل قيدٍ بمفتاح
 * مصدره، فالإعادة لا تكرّر شيئاً.
 */
class JournalBackfill
{
    /** حدثٌ واحد للشحنة: صفوفها المتتالية في دقيقتين (التسليم وعمولته وما دُفع مقدّماً معاً) */
    protected const EVENT_GAP_SECONDS = 120;

    public function __construct(protected Journal $journal, protected PostingRules $rules) {}

    /** @return array{transactions: int, cash: int, expenses: int, withdrawals: int} */
    public function run(Company $company): array
    {
        return Tenancy::runFor($company, function () use ($company) {
            $counts = $this->pass($company, force: true, after: []);
            $this->journal->markInitialized($company->id);

            // ما كُتب أثناء البناء ولم يُقيَّد حيّاً (الشركة لم تكن مهيّأة)
            $this->pass($company, force: false, after: $counts['last']);

            unset($counts['last']);

            return $counts;
        });
    }

    /**
     * @param  array<string, int>  $after  آخر معرّفٍ عولج من كل جدول: يُبدأ بعده
     * @return array{transactions: int, cash: int, expenses: int, withdrawals: int, last: array<string, int>}
     */
    protected function pass(Company $company, bool $force, array $after): array
    {
        $counts = ['transactions' => 0, 'cash' => 0, 'expenses' => 0, 'withdrawals' => 0, 'last' => []];

        // حركات الحسابات بترتيبها، وإيراد كل شحنةٍ في نهاية حدثها
        $pending = [];
        Transaction::query()->where('id', '>', $after['transactions'] ?? 0)->orderBy('id')
            ->chunkById(1000, function ($rows) use ($force, &$pending, &$counts) {
                foreach ($rows as $tx) {
                    $at = $tx->created_at ?? now();

                    if ($tx->shipment_id) {
                        $open = $pending[$tx->shipment_id] ?? null;

                        if ($open && $at->diffInSeconds($open['at'], true) > self::EVENT_GAP_SECONDS) {
                            $this->recognize($tx->shipment_id, $open, $force);
                        }
                    }

                    $this->rules->transaction($tx, $force);
                    $counts['transactions']++;
                    $counts['last']['transactions'] = (int) $tx->id;

                    if ($tx->shipment_id) {
                        $pending[$tx->shipment_id] = ['bound' => (int) $tx->id, 'at' => $at];
                    }
                }

                // أحداثٌ انتهت قبل آخر صفٍّ بأكثر من الفاصل: تُغلق الآن فلا تتراكم في الذاكرة
                $now = $rows->last()?->created_at ?? now();
                foreach ($pending as $shipmentId => $open) {
                    if ($now->diffInSeconds($open['at'], true) > self::EVENT_GAP_SECONDS) {
                        $this->recognize($shipmentId, $open, $force);
                        unset($pending[$shipmentId]);
                    }
                }
            });

        foreach ($pending as $shipmentId => $open) {
            $this->recognize($shipmentId, $open, $force);
        }

        CashMovement::query()->where('id', '>', $after['cash'] ?? 0)->orderBy('id')
            ->chunkById(1000, function ($rows) use ($force, &$counts) {
                foreach ($rows as $movement) {
                    $this->rules->cashMovement($movement, $force);
                    $counts['cash']++;
                    $counts['last']['cash'] = (int) $movement->id;
                }
            });

        // المصروف وإلغاؤه — والإلغاء يُلتقط ولو كان المصروف نفسه قديماً
        Expense::query()->orderBy('id')->chunkById(1000, function ($rows) use ($force, &$counts) {
            foreach ($rows as $expense) {
                $this->rules->expenseRecorded($expense, $force);
                if ($expense->status === 'cancelled') {
                    $this->rules->expenseCancelled($expense, $force);
                }
                $counts['expenses']++;
            }
        });

        // ما طُلب أو اعتُمد ولم يُدفع، أو أُلغي قبل الدفع، لم يحرّك مالاً: لا قيد له
        ProfitWithdrawal::query()->whereIn('status', ['paid', 'reversed'])->orderBy('id')->chunkById(500, function ($rows) use ($force, &$counts) {
            foreach ($rows as $withdrawal) {
                $this->rules->withdrawal($withdrawal, $force);
                if ($withdrawal->isReversed()) {
                    $this->rules->withdrawalCancelled($withdrawal, $force);
                }
                $counts['withdrawals']++;
            }
        });

        $counts['last'] += ['transactions' => $after['transactions'] ?? 0, 'cash' => $after['cash'] ?? 0];

        return $counts;
    }

    /** @param  array{bound: int, at: Carbon}  $event */
    protected function recognize(int $shipmentId, array $event, bool $force): void
    {
        $shipment = Shipment::withTrashed()->find($shipmentId);

        if ($shipment) {
            DB::transaction(fn () => $this->rules->recognizeShipment($shipment, $event['bound'], $event['at'], $force));
        }
    }
}
