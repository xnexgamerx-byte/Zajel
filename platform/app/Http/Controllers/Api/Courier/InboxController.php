<?php

namespace App\Http\Controllers\Api\Courier;

use App\Actions\Notify\Announce;
use App\Actions\Support\CourierChat;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\CourierMessage;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * الإشعارات والمحادثة مع المكتب في تطبيق المندوب (docs/plan/71): ما في جرس شاشته ومحادثتها في
 * الموقع نفسه — يُقرأ هنا فيُعلَّم مقروءاً هناك.
 */
class InboxController extends Controller
{
    public function notifications(Request $request, Announce $announce): JsonResponse
    {
        $user = $request->user();

        $announcements = Announcement::for($user)
            ->with(['reads' => fn ($q) => $q->where('user_id', $user->id)])
            ->latest('id')->limit(50)->get();
        // «جديد» يُحسب قبل تسجيل القراءة، وإلا لم يُرَ شيءٌ جديداً أبداً
        $fresh = $announcements->filter(fn ($a) => $a->reads->isEmpty())->pluck('id')->all();
        $announce->markRead($announcements, $user);

        return response()->json([
            'data' => $announcements->map(fn (Announcement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'body' => $a->body,
                'fresh' => in_array($a->id, $fresh, true),
                'at' => $a->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** المحادثة مع المكتب (docs/plan/38): آخر مئة رسالة، وتُعلَّم مقروءةً عنده */
    public function chat(Request $request, CourierChat $chat): JsonResponse
    {
        $courier = $request->attributes->get('courier');
        $thread = $chat->thread($courier);
        $chat->markRead($thread, CourierChat::COURIER);

        return response()->json([
            'data' => $thread->messages()->with('shipment:id,number,recipient_name,delivery_courier_id,pickup_courier_id')
                // الأحدث مئةً ثم بترتيب كتابتها (messages() مرتّبةٌ تصاعدياً: تُعاد ترتيباً)
                ->reorder('id', 'desc')->limit(100)->get()->reverse()->values()
                ->map(fn (CourierMessage $m) => [
                    'id' => $m->id,
                    'mine' => $m->author === CourierChat::COURIER,
                    'author' => $m->author === CourierChat::COURIER ? 'أنت' : $m->author_name,
                    'body' => $m->body,
                    // رقم الشحنة يفتحها إن كانت شحنته (docs/plan/46)، وإلا يُذكر رقمها فقط
                    'shipment' => $m->shipment ? [
                        'id' => in_array($courier->id, [$m->shipment->delivery_courier_id, $m->shipment->pickup_courier_id], true)
                            ? $m->shipment->id : null,
                        'number' => $m->shipment->number,
                        'recipient' => $m->shipment->recipient_name,
                    ] : null,
                    'at' => $m->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function send(Request $request, CourierChat $chat): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'shipment_id' => ['nullable', 'integer'],
        ], ['body.required' => 'اكتب رسالتك.'], ['body' => 'الرسالة']);

        // شحناته وحدها: لا يكتب عن شحنة غيره برقمها
        $shipment = filled($data['shipment_id'] ?? null)
            ? Shipment::query()->visibleTo($request->user())->find($data['shipment_id'])
            : null;

        $chat->send($request->attributes->get('courier'), $data['body'], $request->user(), CourierChat::COURIER, $shipment);

        return response()->json(['message' => 'أُرسلت للمكتب.'], 201);
    }
}
