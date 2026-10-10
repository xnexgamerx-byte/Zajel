<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Actions\Support\Converse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Merchant;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «الدعم» في تطبيق التاجر (docs/plan/56): محادثاته مع الشركة كما في بوابته — يبدأها بموضوعٍ
 * ورسالة (وصورة أو PDF)، ويردّ، ويرى ردّ الموظّف — وواتساب الدعم وهاتف الشكاوى.
 *
 * كلّ ما هنا لتاجر الحساب وحده: محادثة تاجرٍ آخر «غير موجودة».
 */
class SupportController extends Controller
{
    public function __construct(protected Converse $converse) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        $page = Conversation::where('merchant_id', $merchant->id)->with('shipment:id,number')
            ->orderByDesc('last_message_at')->paginate(20);

        return response()->json([
            'whatsapp'   => Phone::whatsappUrl($company->supportWhatsappFor($merchant->governorate_id), 'مرحباً، أنا '.$merchant->business_name),
            'complaints' => $company->setting('support.complaints'),
            'page'       => $page->currentPage(),
            'last'       => $page->lastPage(),
            'data'       => $page->getCollection()->map(fn (Conversation $c) => [
                'id'       => $c->id,
                'subject'  => $c->subject,
                'shipment' => $c->shipment?->number,
                'closed'   => $c->status === 'closed',
                'unread'   => (bool) $c->merchant_unread,
                'staff'    => $c->last_author === Converse::STAFF,
                'at'       => $c->last_message_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject'         => ['required', 'string', 'min:3', 'max:160'],
            'body'            => ['nullable', 'required_without:attachment', 'string', 'max:2000'],
            'attachment'      => Converse::ATTACHMENT_RULE,
            'shipment_number' => ['nullable', 'string', 'max:40'],
        ], [], ['subject' => 'الموضوع', 'body' => 'الرسالة', 'attachment' => 'الملف', 'shipment_number' => 'رقم الوصل']);

        $conversation = $this->converse->start(
            $request->attributes->get('merchant'), $data['subject'], (string) ($data['body'] ?? ''), $request->user(),
            Converse::MERCHANT, $data['shipment_number'] ?? null, $request->file('attachment'),
        );

        return response()->json(['id' => $conversation->id, 'message' => 'وصلت رسالتك، وستجد الردّ هنا.'], 201);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->own($request, $conversation);

        $conversation->load(['shipment:id,number', 'messages']);
        $this->converse->markRead($conversation, Converse::MERCHANT);

        return response()->json([
            'id'       => $conversation->id,
            'subject'  => $conversation->subject,
            'shipment' => $conversation->shipment?->number,
            'closed'   => $conversation->status === 'closed',
            'messages' => $conversation->messages->map(fn (ConversationMessage $m) => [
                'id'     => $m->id,
                'mine'   => $m->author === Converse::MERCHANT,
                'author' => $m->author === Converse::MERCHANT ? null : ($m->author_name ?: 'الشركة'),
                'body'   => $m->body,
                'file'   => $m->attachmentAvailable() ? [
                    'name'  => $m->attachment_name,
                    'image' => $m->attachmentIsImage(),
                    'size'  => $m->attachmentSizeLabel(),
                    'url'   => route('api.merchant.support.file', [$conversation, $m]),
                ] : null,
                'at'     => $m->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function reply(Request $request, Conversation $conversation): JsonResponse
    {
        $this->own($request, $conversation);

        $data = $request->validate([
            'body'       => ['nullable', 'required_without:attachment', 'string', 'max:2000'],
            'attachment' => Converse::ATTACHMENT_RULE,
        ], [], ['body' => 'الرسالة', 'attachment' => 'الملف']);

        $this->converse->reply($conversation, (string) ($data['body'] ?? ''), $request->user(), Converse::MERCHANT, $request->file('attachment'));

        return response()->json(['message' => 'أُرسلت.'], 201);
    }

    public function file(Request $request, Conversation $conversation, ConversationMessage $message): StreamedResponse
    {
        $this->own($request, $conversation);
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);

        return $message->attachmentResponse();
    }

    protected function own(Request $request, Conversation $conversation): void
    {
        abort_unless((int) $conversation->merchant_id === (int) $request->attributes->get('merchant')->id, 404);
    }
}
