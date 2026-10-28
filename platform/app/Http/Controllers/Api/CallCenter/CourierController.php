<?php

namespace App\Http\Controllers\Api\CallCenter;

use App\Actions\Support\CourierChat;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierMessage;
use App\Models\CourierThread;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «رسائل المناديب» في تطبيق الكول سنتر (docs/plan/81): ما كان يُقرأ من الموقع وحده — محادثة كلّ مندوبٍ
 * مع المكتب، وما ينتظر ردّنا أوّلاً. تقرؤها وتردّ فيها، ورقم الشحنة على السطر إن كان الكلام عنها.
 * بـCourierChat نفسه الذي تكتب به شاشة الموقع.
 */
class CourierController extends Controller
{
    use Concerns;

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        $threads = CourierThread::visibleTo($request->user())
            ->with('courier:id,name,phone,code')
            ->whereNotNull('last_message_at')
            ->when($q !== '', fn ($w) => $w->whereIn('courier_id', Courier::withTrashed()->select('id')
                ->where(fn ($c) => $c->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%"))))
            ->orderByDesc('staff_unread')
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        $last = CourierMessage::query()->whereIn('id', CourierMessage::query()->whereIn('courier_thread_id', $threads->pluck('id'))
            ->selectRaw('max(id)')->groupBy('courier_thread_id'))->get()->keyBy('courier_thread_id');

        // وكلّ مندوبٍ لم تُفتح معه محادثةٌ بعد، تحتها: الكول سنتر يكتب لأيّ مندوبٍ متى شاء (docs/plan/93)
        $others = Courier::visibleTo($request->user())->where('status', 'active')
            ->whereNotIn('id', $threads->pluck('courier_id'))
            ->when($q !== '', fn ($c) => $c->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")))
            ->orderBy('name')->limit(200)->get(['id', 'name', 'phone']);

        return response()->json(['data' => $threads->map(fn (CourierThread $t) => [
            'id' => $t->courier_id,
            'name' => $t->courier?->name,
            'phone' => $t->courier?->phone,
            'unread' => (bool) $t->staff_unread,
            'waiting' => $t->last_author === CourierChat::COURIER,
            'last' => $last->get($t->id)?->body,
            'waiting_minutes' => self::minutesSince($t->last_message_at),
            'at' => $t->last_message_at?->toIso8601String(),
        ])->concat($others->map(fn (Courier $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'unread' => false,
            'waiting' => false,
            'last' => null,
            'waiting_minutes' => 0,
            'at' => null,
        ]))->values()]);
    }

    /** محادثة المندوب — وفتحها قراءتها */
    public function show(Request $request, int $courier, CourierChat $chat): JsonResponse
    {
        $c = Courier::withTrashed()->visibleTo($request->user())->findOrFail($courier);
        $thread = CourierThread::query()->where('courier_id', $c->id)->first();

        if ($thread) {
            $chat->markRead($thread, CourierChat::STAFF);
        }

        $messages = $thread ? $thread->messages()->with('shipment:id,number')->reorder()->latest('id')->limit(100)->get()->sortBy('id')->values() : collect();

        return response()->json(['data' => [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'messages' => $messages->map(fn (CourierMessage $m) => self::courierLine($m) + ['shipment_id' => $m->shipment_id]),
        ]]);
    }

    public function reply(Request $request, int $courier, CourierChat $chat): JsonResponse
    {
        $c = Courier::visibleTo($request->user())->findOrFail($courier);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'shipment' => ['nullable', 'string', 'max:40'],
        ], [], ['body' => 'الرسالة', 'shipment' => 'رقم الشحنة']);

        $shipment = filled($data['shipment'] ?? null)
            ? Shipment::query()->visibleTo($request->user())
                ->where(fn ($w) => $w->where('number', trim($data['shipment']))->orWhere('barcode', trim($data['shipment'])))->first()
            : null;
        abort_if(filled($data['shipment'] ?? null) && ! $shipment, 422, 'لا شحنة بهذا الرقم.');

        $chat->send($c, $data['body'], $request->user(), CourierChat::STAFF, $shipment);
        $chat->markRead($chat->thread($c)->refresh(), CourierChat::STAFF);

        return response()->json(['message' => 'وصلت المندوب.'], 201);
    }
}
