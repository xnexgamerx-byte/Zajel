<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Waybills\IssueWaybillBook;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\WaybillBook;
use App\Support\WaybillPrint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «وصولات للطباعة» في بوابة التاجر: يطبع متى شاء دفتراً من الوصولات بمقاس
 * طابعته (٨٠×١٢٠ أو ١٠٠×١٠٠)، يكتب عليها بيده ويلصقها على طروده. أرقامها له
 * وحده، ويرى كم استُعمل من كل دفتر ويعيد طباعته.
 */
class WaybillController extends Controller
{
    public function index(Request $request): View
    {
        return view('portal.waybills.index', [
            'books' => WaybillBook::query()->where('merchant_id', $request->user()->merchant_id)
                ->withCount(['shipments as used_count' => fn ($q) => $q->withTrashed()])
                ->latest('id')
                ->paginate(config('zajel.per_page')),
        ]);
    }

    public function store(Request $request, IssueWaybillBook $issue): RedirectResponse
    {
        $data = $request->validate([
            'size'       => ['required', 'integer', 'min:1', 'max:'.WaybillBook::MAX_SIZE],
            'print_size' => ['required', Rule::in(array_keys(WaybillBook::PRINT_SIZES))],
        ], [], ['size' => 'عدد الوصولات', 'print_size' => 'المقاس']);

        $merchant = Merchant::findOrFail($request->user()->merchant_id);
        $book = $issue->handle($merchant, (int) $data['size'], $request->user(), 'portal');

        return redirect()->route('portal.waybills.print', ['book' => $book, 'size' => $data['print_size']]);
    }

    public function print(Request $request, WaybillBook $book): View
    {
        abort_unless((int) $book->merchant_id === (int) $request->user()->merchant_id, 404);

        return WaybillPrint::view($request, $book, route('portal.waybills.index'));
    }
}
