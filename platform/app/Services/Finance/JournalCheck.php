<?php

namespace App\Services\Finance;

use App\Support\Arabic;
use App\Support\Finance\Accounts as A;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * مطابقة دفتر القيود بالدفاتر الفرعية (docs/plan/68 §٩-٤): الصناديق، وعهد المناديب وعمولاتهم،
 * وحسابات التجّار — كلٌّ برصيد حسابه في الدفتر. والحسابات الوسيطة تُصفَّر، والميزان يتوازن،
 * وسلسلة البصمات لم تنكسر.
 *
 * النتيجة: ok (مطابق)، note (رقمٌ يُقرأ لا خطأ)، fail (فرقٌ يُبحث).
 */
class JournalCheck
{
    public function __construct(protected Journal $journal) {}

    /** @return list<array{key: string, label: string, status: string, value: int, detail: string}> */
    public function run(): array
    {
        $companyId = (int) Tenancy::id();

        if (! $this->journal->isInitialized($companyId)) {
            return [[
                'key' => 'initialized', 'label' => 'بناء الدفتر من التاريخ', 'status' => 'note', 'value' => 0,
                'detail' => 'لم يُبنَ بعد — يجري تلقائياً خلال دقائق (finance:journal-backfill).',
            ]];
        }

        $lines = DB::table('journal_lines')->where('company_id', $companyId);
        $balance = fn (string $account) => (int) (clone $lines)->where('account', $account)->sum(DB::raw('debit - credit'));

        $checks = [];
        $add = function (string $key, string $label, bool $ok, int $value, string $detail, bool $note = false) use (&$checks) {
            $checks[] = ['key' => $key, 'label' => $label, 'status' => $ok ? 'ok' : ($note ? 'note' : 'fail'), 'value' => $value, 'detail' => $detail];
        };

        $totals = (clone $lines)->selectRaw('coalesce(sum(debit), 0) as d, coalesce(sum(credit), 0) as c')->first();
        $add('balanced', 'ميزان المراجعة متوازن', (int) $totals->d === (int) $totals->c, (int) $totals->d - (int) $totals->c,
            'مجموع المدين '.number_format((int) $totals->d).' ومجموع الدائن '.number_format((int) $totals->c).'.');

        foreach ([
            ['transactions', 'transaction', 'حركات حسابات التجّار والمناديب'],
            ['cash_movements', 'cash_movement', 'حركات الصناديق'],
        ] as [$table, $source, $label]) {
            $rows = (int) DB::table($table)->where('company_id', $companyId)
                ->when($table === 'transactions', fn ($q) => $q->whereIn('account_type', ['merchant', 'courier']))->count();
            $posted = (int) DB::table('journal_entries')->where('company_id', $companyId)->where('source_type', $source)->count();
            $add("coverage_{$source}", "كل {$label} مقيَّدة", $rows === $posted, $rows - $posted,
                number_format($posted).' قيداً من '.number_format($rows).' حركة.');
        }

        $boxes = $this->mismatches(
            DB::table('cash_boxes')->where('company_id', $companyId)->pluck('balance', 'id'),
            (clone $lines)->where('account', A::CASH)->groupBy('cash_box_id')->selectRaw('cash_box_id as id, sum(debit - credit) as v')->pluck('v', 'id'),
        );
        $add('boxes', 'الصناديق = حساب النقد', $boxes[0] === 0, $boxes[1], $boxes[0] ? Arabic::count($boxes[0], ['صندوقٌ واحد', 'صندوقان', 'صناديق', 'صندوقاً']).' يختلف رصيده عن قيوده.' : 'كل صندوقٍ برصيد قيوده.');

        $couriers = DB::table('couriers')->where('company_id', $companyId);
        $custody = $this->mismatches(
            (clone $couriers)->pluck('cash_in_hand', 'id'),
            (clone $lines)->whereIn('account', [A::CUSTODY, A::SHORTAGES])->groupBy('courier_id')->selectRaw('courier_id as id, sum(debit - credit) as v')->pluck('v', 'id'),
        );
        $add('custody', 'عهد المناديب = العهدة وعجزها', $custody[0] === 0, $custody[1], $custody[0] ? Arabic::count($custody[0], ['مندوبٌ واحد', 'مندوبان', 'مناديب', 'مندوباً']).' تختلف عهدته عن قيوده.' : 'عهدة كل مندوبٍ بقيوده.');

        $commissions = $this->mismatches(
            (clone $couriers)->pluck('commission_balance', 'id'),
            (clone $lines)->where('account', A::COMMISSIONS)->groupBy('courier_id')->selectRaw('courier_id as id, sum(credit - debit) as v')->pluck('v', 'id'),
        );
        $add('commissions', 'عمولات المناديب = حساب العمولات', $commissions[0] === 0, $commissions[1], $commissions[0] ? Arabic::count($commissions[0], ['مندوبٌ واحد', 'مندوبان', 'مناديب', 'مندوباً']).' تختلف عمولته عن قيوده.' : 'مطابقة.');

        $merchants = $this->mismatches(
            DB::table('merchants')->where('company_id', $companyId)->pluck('balance', 'id'),
            (clone $lines)->where('account', A::MERCHANTS)->groupBy('merchant_id')->selectRaw('merchant_id as id, sum(credit - debit) as v')->pluck('v', 'id'),
        );
        $add('merchants', 'أرصدة التجّار = حسابات التجّار', $merchants[0] === 0, $merchants[1], $merchants[0] ? Arabic::count($merchants[0], ['تاجرٌ واحد', 'تاجران', 'تجّار', 'تاجراً']).' يختلف رصيده عن قيوده.' : 'رصيد كل تاجرٍ بقيوده.');

        $open = (clone $lines)->where('account', A::SHIPMENT_CLEARING)->groupBy('shipment_id')->havingRaw('sum(debit - credit) <> 0')->select('shipment_id')->get()->count();
        $add('clearing', 'وسيط الشحنات مُصفَّر', $open === 0, $balance(A::SHIPMENT_CLEARING), $open ? Arabic::shipments($open).' لم يُثبَت إيرادها كاملاً.' : 'كل شحنةٍ أُثبت إيرادها.');

        $add('transfers', 'المناقلات بين الصناديق متقاصّة', $balance(A::BOX_TRANSFERS) === 0, $balance(A::BOX_TRANSFERS), 'ما خرج من صندوقٍ دخل غيره.');
        $add('unmapped', 'لا حركة بلا قاعدة قيد', $balance(A::UNMAPPED) === 0 && ! (clone $lines)->where('account', A::UNMAPPED)->exists(), $balance(A::UNMAPPED),
            'حركاتٌ بفئةٍ لا تعرفها قواعد القيد تُجمع هنا.');

        // شحناتٌ أُغلقت ولا إيراد لها في الدفتر: لم تُقيَّد في حساب تاجرها أصلاً (zajel:reconcile يعدّها)، أو أجورها صفر
        $unbooked = DB::table('shipments')->where('shipments.company_id', $companyId)->whereNull('shipments.deleted_at')
            ->where(fn ($q) => $q->whereNotNull('shipments.delivered_at')->orWhere('shipments.status', 'returned'))
            ->where(fn ($q) => $q->where('shipments.total_fees', '>', 0)->orWhere('shipments.return_fee', '>', 0))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('journal_lines')
                ->whereColumn('journal_lines.shipment_id', 'shipments.id')
                ->where('journal_lines.company_id', $companyId)
                ->whereIn('journal_lines.account', [A::DELIVERY_REVENUE, A::RETURN_REVENUE]))
            ->count();
        $add('unbooked', 'شحنات مغلقة بلا إيراد في الدفتر', $unbooked === 0, $unbooked,
            $unbooked ? Arabic::shipments($unbooked).' سُلِّمت أو رجعت بأجورٍ ولم تُقيَّد في حساب تاجرها — تظهر في «تدقيق الحسابات».' : 'كل شحنةٍ مغلقة لها إيرادها.', true);

        $bridge = $balance(A::CASH_BRIDGE);
        $add('bridge', 'نقدٌ تحرّك خارج الصناديق', $bridge === 0, $bridge,
            'تسليم مندوبٍ أو دفعٌ لتاجر أو سحبٌ سُجِّل بلا صندوق (دفعٌ إلكتروني، أو لا صندوق مفعّل). موجبه نقدٌ خرج من الدفاتر، وسالبه نقدٌ دخل بلا صندوق.', true);

        $outside = $balance(A::OUTSIDE_COURIER);
        $add('outside', 'تحصيلٌ بلا مندوبٍ مسجَّل', $outside === 0, $outside, 'شحناتٌ سُلِّمت بلا مندوب: نقدها قُبض ولا عهدة تحمله.', true);

        $broken = $this->journal->brokenAt($companyId);
        $add('chain', 'سلسلة البصمات سليمة', $broken === null, (int) $broken, $broken ? "انكسرت عند القيد رقم {$broken}: عُدِّل أو حُذف قيدٌ خارج النظام." : 'لا قيد عُدِّل أو حُذف.');

        return $checks;
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $stored
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $journal
     * @return array{int, int} عدد المختلفين ومجموع الفروق
     */
    protected function mismatches($stored, $journal): array
    {
        $count = 0;
        $sum = 0;

        foreach ($stored->keys()->merge($journal->keys())->unique() as $id) {
            $diff = (int) ($stored[$id] ?? 0) - (int) ($journal[$id] ?? 0);
            if ($diff !== 0) {
                $count++;
                $sum += $diff;
            }
        }

        return [$count, $sum];
    }
}
