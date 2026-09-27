<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Services\CashBook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «صندوقي»: ما قبضه الموظّف بيده — من المناديب عند إقفال كشوفهم — وما دفع
 * منه، وتسليمه للقاصة بمناقلةٍ تحمل اسمه. الصندوق يُفتح له من القاصة.
 */
class MyCashBoxController extends Controller
{
    public function __construct(protected CashBook $cash) {}

    public function index(Request $request): View
    {
        $box = CashBox::where('user_id', $request->user()->id)->firstOrFail();

        return view('tenant.cash.mine', [
            'box'       => $box,
            'movements' => CashMovement::with('user:id,name')->where('cash_box_id', $box->id)
                ->latest('id')->paginate(config('zajel.per_page'))->withQueryString(),
            'today'     => CashMovement::where('cash_box_id', $box->id)->whereOnDate('created_at', today())
                ->selectRaw('direction, sum(amount) as total')->groupBy('direction')->pluck('total', 'direction'),
            'targets'   => $this->targets($box),
        ]);
    }

    /** «سلّمت للقاصة»: مناقلةٌ من صندوقي إلى القاصة أو صندوق الفرع */
    public function handover(Request $request): RedirectResponse
    {
        $box = CashBox::where('user_id', $request->user()->id)->firstOrFail();

        $data = $request->validate([
            'to_box_id' => ['required', 'integer'],
            'amount'    => ['required', 'integer', 'min:1'],
            'note'      => ['nullable', 'string', 'max:200'],
        ], [], ['to_box_id' => 'الصندوق المستلم', 'amount' => 'المبلغ']);

        $to = $this->targets($box)->firstWhere('id', (int) $data['to_box_id']);

        if (! $to) {
            return back()->withErrors(['to_box_id' => 'اختر القاصة أو صندوق فرعٍ مفعّلاً.']);
        }

        if ($data['amount'] > $box->balance) {
            return back()->withErrors(['amount' => 'في صندوقك '.number_format($box->balance).' دينار فقط.']);
        }

        $this->cash->transfer($box, $to, (int) $data['amount'],
            collect(["تسليم {$request->user()->name} للقاصة", $data['note'] ?? null])->filter()->implode(' — '), $request->user());

        return back()->with('success', 'سُلّم '.number_format($data['amount'])." دينار إلى {$to->name}.");
    }

    /** يُسلَّم إلى القاصة أو صندوق فرع — لا إلى صندوق موظّفٍ آخر */
    private function targets(CashBox $box)
    {
        return CashBox::active()->whereNull('user_id')->whereKeyNot($box->id)
            ->orderByRaw("case when type = 'main' then 0 else 1 end")->orderBy('name')->get(['id', 'name', 'type', 'balance']);
    }
}
