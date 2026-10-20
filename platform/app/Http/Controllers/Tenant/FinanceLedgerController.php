<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CashBox;
use App\Models\Courier;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Services\Finance\JournalCheck;
use App\Services\Reports\ReportPeriod;
use App\Support\Finance\Accounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * دفتر القيود (docs/plan/68، المرحلة الأولى): ميزان المراجعة ومطابقته بالدفاتر الفرعية،
 * وسطور القيود بأبعادها — منها يُتتبَّع أي رقم إلى قيده، ومن القيد إلى مصدره.
 */
class FinanceLedgerController extends Controller
{
    public function trialBalance(Request $request, JournalCheck $check): View
    {
        $period = ReportPeriod::fromRequest($request);
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();

        $rows = JournalLine::query()
            ->selectRaw('account,
                sum(case when entry_date < ? then debit - credit else 0 end) as opening,
                sum(case when entry_date >= ? then debit else 0 end) as debit,
                sum(case when entry_date >= ? then credit else 0 end) as credit', [$from, $from, $from])
            ->where('entry_date', '<=', $to)
            ->groupBy('account')
            ->toBase()->get()->keyBy('account');

        $accounts = collect(Accounts::ALL)->map(function ($meta, $code) use ($rows) {
            $row = $rows[$code] ?? null;
            $opening = (int) ($row->opening ?? 0);
            $debit = (int) ($row->debit ?? 0);
            $credit = (int) ($row->credit ?? 0);

            return (object) [
                'code'    => $code,
                'name'    => $meta[0],
                'type'    => $meta[1],
                'opening' => $opening,
                'debit'   => $debit,
                'credit'  => $credit,
                'closing' => $opening + $debit - $credit,
            ];
        })->filter(fn ($a) => $a->opening || $a->debit || $a->credit)->groupBy('type');

        return view('tenant.finance.trial-balance', [
            'period'   => $period,
            'accounts' => $accounts,
            'checks'   => $check->run(),
        ]);
    }

    /** «تتبّع الرقم»: سطور حسابٍ في مدّة، بأيّ بُعد */
    public function lines(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        $account = (string) $request->input('account');
        $shipment = trim((string) $request->input('shipment'));

        $query = JournalLine::query()
            ->with(['entry:id,number,entry_date,event,description,source_type,source_id', 'shipment:id,number', 'courier:id,name', 'merchant:id,business_name', 'cashBox:id,name'])
            // عمود تاريخٍ لا وقت: يُقارَن بالتاريخ نصّاً
            ->where('entry_date', '>=', $period->from->toDateString())
            ->where('entry_date', '<=', $period->to->toDateString())
            ->when(isset(Accounts::ALL[$account]), fn ($q) => $q->where('account', $account))
            ->when($request->integer('courier_id'), fn ($q, $id) => $q->where('courier_id', $id))
            ->when($request->integer('merchant_id'), fn ($q, $id) => $q->where('merchant_id', $id))
            ->when($request->integer('cash_box_id'), fn ($q, $id) => $q->where('cash_box_id', $id))
            ->when($request->integer('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($shipment !== '', fn ($q) => $q->where('shipment_id', Shipment::withTrashed()->where('number', $shipment)->value('id') ?? 0));

        $totals = (clone $query)->toBase()->selectRaw('coalesce(sum(debit), 0) as debit, coalesce(sum(credit), 0) as credit')->first();

        return view('tenant.finance.lines', [
            'period'   => $period,
            'account'  => $account,
            'lines'    => $query->orderByDesc('entry_date')->orderByDesc('id')->paginate(50)->withQueryString(),
            'totals'   => $totals,
            'couriers' => Courier::orderBy('name')->get(['id', 'name']),
            'merchants' => Merchant::orderBy('business_name')->get(['id', 'business_name']),
            'boxes'    => CashBox::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function entry(JournalEntry $entry): View
    {
        $entry->load(['lines.shipment:id,number', 'lines.courier:id,name', 'lines.merchant:id,business_name', 'lines.cashBox:id,name', 'createdBy:id,name']);

        return view('tenant.finance.entry', [
            'entry'  => $entry,
            'source' => $this->source($entry),
        ]);
    }

    /** @return array{string, ?string} اسم المصدر ورابطه */
    protected function source(JournalEntry $entry): array
    {
        $id = (int) $entry->source_id;

        return match ($entry->source_type) {
            'transaction' => (function () use ($id) {
                $tx = DB::table('transactions')->where('id', $id)->first(['shipment_id', 'reference_type', 'reference_id']);

                return match (true) {
                    $tx?->shipment_id !== null => ['الشحنة', route('shipments.show', $tx->shipment_id)],
                    $tx?->reference_type === 'courier_settlement' => ['كشف المندوب', route('settlements.couriers.show', $tx->reference_id)],
                    $tx?->reference_type === 'merchant_settlement' => ['كشف التاجر', route('settlements.merchants.show', $tx->reference_id)],
                    default => ['حركة حساب #'.$id, null],
                };
            })(),
            'cash_movement'     => ['حركة صندوق #'.$id, route('cash.index')],
            'expense'           => ['المصروف', route('expenses.index')],
            'profit_withdrawal' => ['سحب الأرباح', route('profits.withdrawals.index')],
            'shipment'          => ['الشحنة', route('shipments.show', $id)],
            'financial_period'  => ['إقفال الفترة', route('finance.periods.show', $id)],
            'opening_balance'   => ['القيد الافتتاحي', route('finance.opening')],
            default             => ['—', null],
        };
    }
}
