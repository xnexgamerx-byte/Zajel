<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Finance\Accounts as A;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * القيد الافتتاحي (docs/plan/68 ق١٣): ما لا يُعاد بناؤه من التاريخ — رصيدٌ أُدخل قبل الدفتر،
 * أو حركةٌ قبل ميزةٍ بعينها — يُقيَّد مرّةً يوم التشغيل من الأرصدة القائمة. لكل صندوقٍ ومندوبٍ
 * وتاجرٍ يختلف رصيده عن قيوده سطرٌ بالفرق، وطرفها «رأس المال والأرصدة الافتتاحية» (3100).
 * وبعده تتطابق المطابقة، وما قبله يُقرأ «قبل الدفتر».
 */
class OpeningBalances
{
    public const KINDS = [
        'box'        => 'الصناديق',
        'custody'    => 'عهد المناديب',
        'commission' => 'عمولات المناديب',
        'merchant'   => 'أرصدة التجّار',
    ];

    public function __construct(protected Journal $journal) {}

    /**
     * الفرق بين الرصيد المخزَّن وقيوده لكل صاحب حساب، بإشارة الدفتر: موجبه مدينٌ يُضاف.
     *
     * @return list<array{kind: string, id: int, name: string, branch_id: ?int, stored: int, journal: int, diff: int}>
     */
    public function differences(): array
    {
        $company = (int) Tenancy::id();
        $lines = fn () => DB::table('journal_lines')->where('company_id', $company);
        $rows = [];

        $add = function (string $kind, $entities, $journal, callable $stored, int $sign) use (&$rows) {
            foreach ($entities as $entity) {
                $have = (int) ($journal[$entity->id] ?? 0);
                $want = $stored($entity);
                if ($want !== $have) {
                    $rows[] = ['kind' => $kind, 'id' => (int) $entity->id, 'name' => (string) $entity->name,
                        'branch_id' => $entity->branch_id === null ? null : (int) $entity->branch_id,
                        'stored' => $want, 'journal' => $have, 'diff' => $sign * ($want - $have)];
                }
            }
        };

        // الصندوق والعهدة مدينان؛ العمولة ورصيد التاجر دائنان
        $add('box', DB::table('cash_boxes')->where('company_id', $company)->get(['id', 'name', 'branch_id', 'balance']),
            $lines()->where('account', A::CASH)->groupBy('cash_box_id')->selectRaw('cash_box_id as id, sum(debit - credit) as v')->pluck('v', 'id'),
            fn ($e) => (int) $e->balance, 1);

        $couriers = DB::table('couriers')->where('company_id', $company)->get(['id', 'name', 'branch_id', 'cash_in_hand', 'commission_balance']);
        $add('custody', $couriers,
            $lines()->whereIn('account', [A::CUSTODY, A::SHORTAGES])->groupBy('courier_id')->selectRaw('courier_id as id, sum(debit - credit) as v')->pluck('v', 'id'),
            fn ($e) => (int) $e->cash_in_hand, 1);
        $add('commission', $couriers,
            $lines()->where('account', A::COMMISSIONS)->groupBy('courier_id')->selectRaw('courier_id as id, sum(credit - debit) as v')->pluck('v', 'id'),
            fn ($e) => (int) $e->commission_balance, -1);

        $add('merchant', DB::table('merchants')->where('company_id', $company)->selectRaw('id, business_name as name, branch_id, balance')->get(),
            $lines()->where('account', A::MERCHANTS)->groupBy('merchant_id')->selectRaw('merchant_id as id, sum(credit - debit) as v')->pluck('v', 'id'),
            fn ($e) => (int) $e->balance, -1);

        return $rows;
    }

    /** يكتب القيد الافتتاحي بفروق اللحظة، بتاريخ يوم التشغيل */
    public function post(Carbon $on, string $reason, User $actor): int
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب القيد الافتتاحي: يبقى معه في الدفتر وسجلّ التدقيق.']);
        }

        if (! $this->journal->isInitialized((int) Tenancy::id())) {
            throw ValidationException::withMessages(['opening' => 'لم يكتمل بناء الدفتر من التاريخ بعد: انتظره ثم أعد المحاولة.']);
        }

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['opened_on' => 'يوم التشغيل اليوم أو قبله.']);
        }

        return DB::transaction(function () use ($on, $reason, $actor) {
            DB::table('companies')->where('id', $actor->company_id)->lockForUpdate()->first();
            $rows = $this->differences();

            if ($rows === []) {
                throw ValidationException::withMessages(['opening' => 'لا فرق: كل رصيدٍ يطابق قيوده، فلا قيد افتتاحيّ.']);
            }

            $lines = [];
            $net = 0;
            foreach ($rows as $row) {
                [$account, $dims] = match ($row['kind']) {
                    'box'        => [A::CASH, ['cash_box_id' => $row['id']]],
                    'custody'    => [A::CUSTODY, ['courier_id' => $row['id']]],
                    'commission' => [A::COMMISSIONS, ['courier_id' => $row['id']]],
                    'merchant'   => [A::MERCHANTS, ['merchant_id' => $row['id']]],
                };
                $lines[] = ['account' => $account, ($row['diff'] > 0 ? 'debit' : 'credit') => abs($row['diff']), 'branch_id' => $row['branch_id']] + $dims;
                $net += $row['diff'];
            }
            if ($net !== 0) {
                $lines[] = ['account' => A::OPENING, ($net > 0 ? 'credit' : 'debit') => abs($net)];
            }

            $entry = $this->journal->post((int) $actor->company_id, 'opening:'.now()->format('YmdHisv'), 'opening_balance',
                $on->copy()->setTime(0, 0), $lines, 'opening_balance', null, mb_substr('قيد افتتاحي: '.$reason, 0, 255), $actor->id, true);

            AuditLog::create([
                'user_id'        => $actor->id,
                'user_name'      => $actor->name,
                'action'         => 'opening_balance_posted',
                'auditable_type' => 'journal_entry',
                'auditable_id'   => $entry,
                'new_values'     => ['opened_on' => $on->toDateString(), 'lines' => count($lines), 'net' => $net]
                    + collect($rows)->groupBy('kind')->map(fn ($g) => $g->sum('diff'))->all(),
                'reason'         => mb_substr($reason, 0, 255),
                'ip'             => request()->ip(),
            ]);

            return (int) $entry;
        });
    }
}
