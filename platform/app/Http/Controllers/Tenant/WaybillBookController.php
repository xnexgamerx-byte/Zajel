<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Waybills\IssueWaybillBook;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\WaybillBook;
use App\Support\WaybillPrint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «دفاتر الوصولات المطبوعة» — «ستيكرات العملاء والمتاجر» و«إسناد الوصولات للمتاجر»
 * في المعتاد: كل دفترٍ وأرقامه ولمن هو وكم استُعمل منه، ودفترٌ جديد لتاجرٍ أو
 * للمخزن، وإسناد ما في المخزن، وطباعته. والتاجر يُصدر دفاتره من بوابته أيضاً.
 */
class WaybillBookController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('tenant.waybills.index', [
            'books' => WaybillBook::visibleTo($user)
                ->with(['merchant:id,business_name', 'creator:id,name'])
                ->withCount(['shipments as used_count' => fn ($q) => $q->withTrashed()])
                ->when($request->integer('merchant_id'), fn ($q, $id) => $q->where('merchant_id', $id))
                ->when($request->query('assigned') === 'no', fn ($q) => $q->whereNull('merchant_id'))
                ->latest('id')
                ->paginate(config('zajel.per_page'))
                ->withQueryString(),
            'merchants' => Merchant::where('status', 'active')->visibleTo($user)->orderBy('business_name')->get(['id', 'business_name']),
            // «أسنِده» من صفّ دفترٍ في المخزن: نموذجٌ واحد بقائمة التجّار، لا قائمةٌ في كل صفّ
            'assigning' => $request->integer('assign')
                ? WaybillBook::visibleTo($user)->whereNull('merchant_id')->find($request->integer('assign'))
                : null,
        ]);
    }

    public function store(Request $request, IssueWaybillBook $issue): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id' => ['nullable', 'integer'],
            'size'        => ['required', 'integer', 'min:1', 'max:'.WaybillBook::MAX_SIZE],
            'note'        => ['nullable', 'string', 'max:255'],
        ], [], ['merchant_id' => 'التاجر', 'size' => 'عدد الوصولات', 'note' => 'الملاحظة']);

        $merchant = null;
        if (! empty($data['merchant_id']) && ! ($merchant = Merchant::visibleTo($request->user())->find($data['merchant_id']))) {
            return back()->withErrors(['merchant_id' => 'التاجر غير موجود.']);
        }

        $book = $issue->handle($merchant, (int) $data['size'], $request->user(), 'office', $data['note'] ?? null);

        return redirect()->route('waybill-books.index')->with('success',
            'دفترٌ جديد '.$book->firstCode().'–'.$book->lastCode().($merchant ? ' لـ'.$merchant->business_name : ' في المخزن حتى يُسنَد').'.');
    }

    public function assign(Request $request, WaybillBook $book, IssueWaybillBook $issue): RedirectResponse
    {
        $data = $request->validate(['merchant_id' => ['required', 'integer']], [], ['merchant_id' => 'التاجر']);

        if (! $merchant = Merchant::visibleTo($request->user())->find($data['merchant_id'])) {
            return back()->withErrors(['merchant_id' => 'التاجر غير موجود.']);
        }

        $issue->assign($book, $merchant, $request->user());

        return redirect()->route('waybill-books.index')
            ->with('success', "أُسند الدفتر {$book->firstCode()}–{$book->lastCode()} إلى {$merchant->business_name}.");
    }

    public function print(Request $request, WaybillBook $book): View
    {
        return WaybillPrint::view($request, $book, route('waybill-books.index'));
    }
}
