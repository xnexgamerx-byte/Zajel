<?php

namespace App\Http\Controllers;

use App\Actions\Notify\Announce;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * صندوق الإشعارات — للمندوب في تطبيقه وللتاجر في بوّابته.
 *
 * فتحُ الصندوق قراءةٌ لما فيه، فلا زرّ «قرأتُ» يُنسى، والمرسِل يرى مَن
 * وصله الإعلان فعلاً لا مَن أُرسل إليه فحسب.
 */
class InboxController extends Controller
{
    public function __construct(protected Announce $announce) {}

    public function courier(Request $request): View
    {
        return view('courier.inbox', $this->open($request));
    }

    public function portal(Request $request): View
    {
        return view('portal.inbox', $this->open($request));
    }

    protected function open(Request $request): array
    {
        $user = $request->user();

        $announcements = Announcement::for($user)
            ->with(['reads' => fn ($q) => $q->where('user_id', $user->id)])
            ->latest('id')
            ->limit(50)
            ->get();

        // «جديد» يُحسب قبل تسجيل القراءة، وإلا لم يُرَ شيءٌ جديداً أبداً
        $fresh = $announcements->filter(fn ($a) => $a->reads->isEmpty())->pluck('id')->all();

        $this->announce->markRead($announcements, $user);

        return ['announcements' => $announcements, 'fresh' => $fresh];
    }
}
