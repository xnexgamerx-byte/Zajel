<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Support\Converse;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * الدعم من جهة التاجر: محادثاته مع الشركة، وواتساب الدعم إن كان لها.
 *
 * كل استعلام هنا مقيَّدٌ بتاجر الحساب فوق تقييد الشركة: رقم محادثةٍ
 * لتاجرٍ آخر في الرابط يُرجع «غير موجود» لا محادثته.
 */
class SupportController extends Controller
{
    public function __construct(protected Converse $converse) {}

    public function index(Request $request): View
    {
        return view('portal.support.index', [
            'conversations' => Conversation::query()
                ->where('merchant_id', $request->user()->merchant_id)
                ->with('shipment:id,number')
                ->orderByDesc('last_message_at')
                ->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject'         => ['required', 'string', 'min:3', 'max:160'],
            'body'            => ['required', 'string', 'max:2000'],
            'shipment_number' => ['nullable', 'string', 'max:40'],
        ], [], ['subject' => 'الموضوع', 'body' => 'الرسالة', 'shipment_number' => 'رقم الوصل']);

        $conversation = $this->converse->start(
            $request->user()->merchant, $data['subject'], $data['body'], $request->user(),
            Converse::MERCHANT, $data['shipment_number'] ?? null,
        );

        return redirect()->route('portal.support.show', $conversation)->with('success', 'وصلت رسالتك، وستجد الردّ هنا.');
    }

    public function show(Request $request, Conversation $conversation): View
    {
        $this->own($request, $conversation);

        $conversation->load(['shipment:id,number', 'messages']);
        $this->converse->markRead($conversation, Converse::MERCHANT);

        return view('portal.support.show', ['conversation' => $conversation]);
    }

    public function reply(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->own($request, $conversation);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']], [], ['body' => 'الرسالة']);

        $this->converse->reply($conversation, $data['body'], $request->user(), Converse::MERCHANT);

        return back();
    }

    protected function own(Request $request, Conversation $conversation): void
    {
        abort_unless((int) $conversation->merchant_id === (int) $request->user()->merchant_id, 404);
    }
}
