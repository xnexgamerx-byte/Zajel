<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Support\Converse;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'body'            => ['nullable', 'required_without:attachment', 'string', 'max:2000'],
            'attachment'      => Converse::ATTACHMENT_RULE,
            'shipment_number' => ['nullable', 'string', 'max:40'],
        ], [], ['subject' => 'الموضوع', 'body' => 'الرسالة', 'attachment' => 'الملف', 'shipment_number' => 'رقم الوصل']);

        $conversation = $this->converse->start(
            $request->user()->merchant, $data['subject'], (string) ($data['body'] ?? ''), $request->user(),
            Converse::MERCHANT, $data['shipment_number'] ?? null, $request->file('attachment'),
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

        $data = $request->validate([
            'body'       => ['nullable', 'required_without:attachment', 'string', 'max:2000'],
            'attachment' => Converse::ATTACHMENT_RULE,
        ], [], ['body' => 'الرسالة', 'attachment' => 'الملف']);

        $this->converse->reply($conversation, (string) ($data['body'] ?? ''), $request->user(), Converse::MERCHANT, $request->file('attachment'));

        return back();
    }

    /** ملفّات محادثاته هو وحده */
    public function attachment(Request $request, Conversation $conversation, ConversationMessage $message): StreamedResponse
    {
        $this->own($request, $conversation);
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);

        return $message->attachmentResponse();
    }

    protected function own(Request $request, Conversation $conversation): void
    {
        abort_unless((int) $conversation->merchant_id === (int) $request->user()->merchant_id, 404);
    }
}
