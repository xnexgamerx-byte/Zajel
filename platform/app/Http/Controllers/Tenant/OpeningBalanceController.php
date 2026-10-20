<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Services\Finance\Journal;
use App\Services\Finance\OpeningBalances;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * «الأرصدة الافتتاحية» (docs/plan/68 ق١٣): ما يختلف من الأرصدة عن قيودها يُعرض صاحباً صاحباً،
 * ويُقيَّد بقيدٍ افتتاحيّ واحدٍ بسببٍ مكتوب — لصاحب الشركة (اعتماد الإقفال).
 */
class OpeningBalanceController extends Controller
{
    public function index(OpeningBalances $opening, Journal $journal): View
    {
        return view('tenant.finance.opening', [
            'initialized' => $journal->isInitialized((int) Tenancy::id()),
            'rows'        => collect($opening->differences())->groupBy('kind'),
            'entries'     => JournalEntry::query()->where('event', 'opening_balance')->with('createdBy:id,name')->latest('id')->get(),
        ]);
    }

    public function store(Request $request, OpeningBalances $opening): RedirectResponse
    {
        $data = $request->validate([
            'opened_on' => ['required', 'date'],
            'reason'    => ['required', 'string', 'max:200'],
        ], [], ['opened_on' => 'يوم التشغيل', 'reason' => 'السبب']);

        $entry = $opening->post(Carbon::parse($data['opened_on']), $data['reason'], $request->user());

        return redirect()->route('finance.entries.show', $entry)->with('success', 'كُتب القيد الافتتاحي: الأرصدة تطابق قيودها الآن.');
    }
}
