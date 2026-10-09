<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Support\StaffChat;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\StaffThread;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «مراسلة الموظّفين» (docs/plan/41): المحادثات بأحدثها وما لم يُقرأ أوّلاً، ومحادثةٌ مفتوحة
 * مع نموذج الردّ. وتُبدأ محادثةٌ بموظّفٍ أو بقسمٍ كلّه — ورقم الشحنة إن كان الكلام عنها.
 */
class StaffChatController extends Controller
{
    public function index(Request $request, StaffChat $chat): View
    {
        $user = $request->user();
        $unread = $chat->unread($user)->pluck('id')->all();

        $threads = $chat->visibleTo($user)
            ->whereNotNull('last_message_at')
            ->with('members:id,name')
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        $thread = $request->integer('thread')
            ? $chat->visibleTo($user)->find($request->integer('thread'))
            : null;

        if ($thread) {
            $chat->markRead($thread, $user);
        }

        $teams = $chat->teams();
        $q = trim((string) $request->query('q', ''));
        $to = (string) $request->query('to', '');

        // الموظّفون أوّلاً، وجنب كلٍّ واتساب؛ والمراسلة داخل النظام تبويبٌ ثانٍ — يُفتح بمحادثةٍ أو بـ«إلى»
        $tab = $thread || $to !== '' || $request->query('tab') === 'chat' || $request->old('body') !== null ? 'chat' : 'people';

        return view('tenant.staff_chat.index', [
            'tab'      => $tab,
            'q'        => $q,
            'directory' => $chat->staff($user)->whereKeyNot($user->id)
                ->when($q !== '', fn ($w) => $w->where(fn ($m) => $m->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
                ->with(['rank:id,name', 'branch:id,name'])->orderBy('name')
                ->get(['id', 'name', 'phone', 'role', 'rank_id', 'branch_id']),
            'threads'  => $threads,
            'unread'   => $unread,
            'thread'   => $thread,
            'title'    => fn (StaffThread $t) => $this->title($t, $user, $teams),
            'messages' => $thread ? $thread->messages()->with('shipment:id,number')->latest('id')->limit(200)->get()->reverse()->values() : collect(),
            'teams'    => $teams,
            'myTeam'   => StaffChat::teamOf($user),
            'people'   => $chat->staff($user)->whereKeyNot($user->id)->with('rank:id,name')->orderBy('name')->get(['id', 'name', 'role', 'rank_id']),
            'to'       => $to,
            'shipment' => (string) $request->query('shipment', ''),
        ]);
    }

    public function send(Request $request, StaffChat $chat): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'thread_id' => ['nullable', 'integer'],
            'to'        => ['required_without:thread_id', 'nullable', 'string', 'max:60'],
            'body'      => ['required', 'string', 'max:2000'],
            'shipment'  => ['nullable', 'string', 'max:40'],
        ], ['to.required_without' => 'إلى من؟ اختر موظّفاً أو قسماً.'], ['to' => 'إلى', 'body' => 'الرسالة', 'shipment' => 'رقم الشحنة']);

        $thread = filled($data['thread_id'] ?? null)
            ? $chat->visibleTo($user)->find($data['thread_id'])
            : $this->open($chat, $user, (string) $data['to']);

        if (! $thread) {
            return back()->withInput()->withErrors(['to' => 'اختر موظّفاً أو قسماً.']);
        }

        $shipment = null;

        if (filled($data['shipment'] ?? null)) {
            $number = trim($data['shipment']);
            $shipment = Shipment::query()->visibleTo($user)
                ->where(fn ($w) => $w->where('number', $number)->orWhere('barcode', $number))->first();

            if (! $shipment) {
                return back()->withInput()->withErrors(['shipment' => 'لا شحنة بهذا الرقم.']);
            }
        }

        $chat->send($thread, $data['body'], $user, $shipment);

        return redirect()->route('staff-chat.index', ['thread' => $thread->id]);
    }

    /** «user:12» موظّفٌ بعينه، و«rank:5» أو «role:accountant» قسمٌ كلّه */
    private function open(StaffChat $chat, User $user, string $to): ?StaffThread
    {
        if (preg_match('/^user:(\d+)$/', $to, $match)) {
            $other = $chat->staff($user)->whereKeyNot($user->id)->find((int) $match[1]);

            return $other ? $chat->between($user, $other) : null;
        }

        return array_key_exists($to, $chat->teams()) ? $chat->forTeam($to) : null;
    }

    /** اسم المحادثة كما يراها $user: القسم، أو الموظّف الآخر */
    private function title(StaffThread $thread, User $user, array $teams): string
    {
        if ($thread->isTeam()) {
            return 'قسم '.($teams[$thread->team] ?? 'لم يعد موجوداً');
        }

        return $thread->members->firstWhere('id', '!=', $user->id)?->name ?? $user->name;
    }
}
