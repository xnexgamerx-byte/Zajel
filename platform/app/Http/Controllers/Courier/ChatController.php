<?php

namespace App\Http\Controllers\Courier;

use App\Actions\Support\CourierChat;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** «المكتب» في تطبيق المندوب: محادثته مع الكول سنتر (docs/plan/38). */
class ChatController extends Controller
{
    public function index(Request $request, CourierChat $chat): View
    {
        $courier = $request->user()->courier;
        $thread = $chat->thread($courier);
        $chat->markRead($thread, CourierChat::COURIER);

        // شحنةٌ من شحناته إن جاء من صفحتها: يُكتب السطر عنها
        $shipment = $request->integer('shipment')
            ? Shipment::query()->visibleTo($request->user())->find($request->integer('shipment'))
            : null;

        return view('courier.chat', [
            'messages' => $thread->messages()->with('shipment:id,number')->latest('id')->limit(100)->get()->reverse()->values(),
            'shipment' => $shipment,
        ]);
    }

    public function send(Request $request, CourierChat $chat): RedirectResponse
    {
        $data = $request->validate([
            'body'        => ['required', 'string', 'max:2000'],
            'shipment_id' => ['nullable', 'integer'],
        ], ['body.required' => 'اكتب رسالتك.'], ['body' => 'الرسالة']);

        // شحناته وحدها: لا يكتب عن شحنة غيره برقمها
        $shipment = filled($data['shipment_id'] ?? null)
            ? Shipment::query()->visibleTo($request->user())->find($data['shipment_id'])
            : null;

        $chat->send($request->user()->courier, $data['body'], $request->user(), CourierChat::COURIER, $shipment);

        return redirect()->route('courier.chat');
    }
}
