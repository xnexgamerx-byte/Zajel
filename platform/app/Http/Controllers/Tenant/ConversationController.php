<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Support\Converse;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Merchant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * المحادثات من جهة الشركة — «المراجعة ← المحادثات» في النظام المرجعي.
 *
 * الترتيب بما ينتظرنا أوّلاً: محادثةٌ كتب التاجر آخرها سؤالٌ بلا جواب،
 * وأقدمها أحقّ بالجواب من أحدثها.
 */
class ConversationController extends Controller
{
    public function __construct(protected Converse $converse) {}

    public function index(Request $request): View
    {
        $filter = in_array($request->query('show'), ['waiting', 'open', 'closed', 'all'], true)
            ? $request->query('show') : 'waiting';

        $base = fn () => Conversation::query()->visibleTo($request->user());

        $conversations = $base()
            ->with(['merchant:id,business_name,code', 'shipment:id,number'])
            ->when($filter === 'waiting', fn ($q) => $q->where('status', 'open')->where('last_author', 'merchant'))
            ->when($filter === 'open', fn ($q) => $q->where('status', 'open'))
            ->when($filter === 'closed', fn ($q) => $q->where('status', 'closed'))
            // المنتظِر: الأقدم أولاً. غيره: الأحدث أولاً
            ->orderBy('last_message_at', $filter === 'waiting' ? 'asc' : 'desc')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.conversations.index', [
            'conversations' => $conversations,
            'filter'        => $filter,
            'counts'        => [
                'waiting' => $base()->where('status', 'open')->where('last_author', 'merchant')->count(),
                'open'    => $base()->where('status', 'open')->count(),
            ],
            'merchants'     => Merchant::where('status', 'active')->orderBy('business_name')->get(['id', 'business_name']),
        ]);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        $this->authorizeVisible($request, $conversation);

        $conversation->load(['merchant:id,business_name,code,phone', 'shipment:id,number,status', 'messages']);
        $this->converse->markRead($conversation, Converse::STAFF);

        return view('tenant.conversations.show', ['conversation' => $conversation]);
    }

    /** الشركة تبدأ أيضاً: «عنوان الشحنة ناقص»، «رصيدك سالب». */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id'     => ['required', 'integer'],
            'subject'         => ['required', 'string', 'min:3', 'max:160'],
            'body'            => ['required', 'string', 'max:2000'],
            'shipment_number' => ['nullable', 'string', 'max:40'],
        ], [], ['merchant_id' => 'التاجر', 'subject' => 'الموضوع', 'body' => 'الرسالة', 'shipment_number' => 'رقم الوصل']);

        $merchant = Merchant::find($data['merchant_id']);

        if (! $merchant || ($request->user()->isBranchLimited() && (int) $merchant->branch_id !== (int) $request->user()->branch_id)) {
            return back()->withErrors(['merchant_id' => 'التاجر غير موجود.'])->withInput();
        }

        $conversation = $this->converse->start(
            $merchant, $data['subject'], $data['body'], $request->user(), Converse::STAFF, $data['shipment_number'] ?? null,
        );

        return redirect()->route('conversations.show', $conversation)->with('success', 'أُرسلت الرسالة إلى '.$merchant->business_name.'.');
    }

    public function reply(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeVisible($request, $conversation);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']], [], ['body' => 'الردّ']);

        $this->converse->reply($conversation, $data['body'], $request->user(), Converse::STAFF);

        return back();
    }

    public function close(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeVisible($request, $conversation);

        $conversation->isOpen()
            ? $this->converse->close($conversation)
            : $this->converse->reopen($conversation);

        return back()->with('success', $conversation->isOpen() ? 'أُعيد فتح المحادثة.' : 'أُغلقت المحادثة.');
    }

    /** المقيَّد بفرعٍ لا يفتح محادثة تاجرٍ من فرعٍ آخر ولو عرف رقمها. */
    protected function authorizeVisible(Request $request, Conversation $conversation): void
    {
        abort_unless(
            Conversation::visibleTo($request->user())->whereKey($conversation->id)->exists(),
            404,
        );
    }
}
