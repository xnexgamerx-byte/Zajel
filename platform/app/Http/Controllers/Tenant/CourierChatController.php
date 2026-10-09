<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Support\CourierChat;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierThread;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «محادثة المناديب» للكول سنتر (docs/plan/38): المحادثات بأحدثها، وما ينتظر ردّنا أوّلاً،
 * ومحادثة المندوب المختار مع نموذج الردّ — ورقم الشحنة إن كان الكلام عنها.
 */
class CourierChatController extends Controller
{
    public function index(Request $request, CourierChat $chat): View
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));

        $threads = CourierThread::visibleTo($user)
            ->with('courier:id,name,phone,code')
            ->whereNotNull('last_message_at')
            ->when($q !== '', fn ($w) => $w->whereIn('courier_id', Courier::withTrashed()->select('id')
                ->where(fn ($c) => $c->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%"))))
            ->orderByDesc('staff_unread')
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        $courier = $request->integer('courier')
            ? Courier::withTrashed()->visibleTo($user)->find($request->integer('courier'))
            : null;

        $thread = $courier ? CourierThread::where('courier_id', $courier->id)->first() : null;

        if ($thread) {
            $chat->markRead($thread, CourierChat::STAFF);
        }

        return view('tenant.courier_chat.index', [
            'threads'  => $threads,
            'courier'  => $courier,
            'messages' => $thread ? $thread->messages()->with('shipment:id,number')->latest('id')->limit(200)->get()->reverse()->values() : collect(),
            'couriers' => Courier::visibleTo($user)->where('status', 'active')->whereIn('type', ['delivery', 'pickup'])
                ->orderBy('name')->get(['id', 'name', 'code']),
            'q'        => $q,
            'shipment' => (string) $request->query('shipment', ''),
        ]);
    }

    public function send(Request $request, CourierChat $chat): RedirectResponse
    {
        $data = $request->validate([
            'courier_id' => ['required', 'integer'],
            'body'       => ['required', 'string', 'max:2000'],
            'shipment'   => ['nullable', 'string', 'max:40'],
        ], [], ['courier_id' => 'المندوب', 'body' => 'الرسالة', 'shipment' => 'رقم الشحنة']);

        $courier = Courier::visibleTo($request->user())->find($data['courier_id']);

        if (! $courier) {
            return back()->withInput()->withErrors(['courier_id' => 'المندوب غير موجود.']);
        }

        $shipment = null;

        if (filled($data['shipment'] ?? null)) {
            $shipment = Shipment::query()->visibleTo($request->user())
                ->where(fn ($w) => $w->where('number', trim($data['shipment']))->orWhere('barcode', trim($data['shipment'])))->first();

            if (! $shipment) {
                return back()->withInput()->withErrors(['shipment' => 'لا شحنة بهذا الرقم.']);
            }
        }

        $chat->send($courier, $data['body'], $request->user(), CourierChat::STAFF, $shipment);

        return redirect()->route('courier-chat.index', ['courier' => $courier->id]);
    }
}
