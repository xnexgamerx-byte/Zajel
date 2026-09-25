<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Notify\Announce;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** الإشعارات الجماعية من جهة الشركة: الكتابة، ومَن قرأ. */
class AnnouncementController extends Controller
{
    public function __construct(protected Announce $announce) {}

    public function index(Request $request): View
    {
        $announcements = Announcement::with('author:id,name')
            ->withCount('reads')
            ->latest('id')
            ->paginate(config('zajel.per_page'));

        // البلوغ لكل جمهور مرّةً لا لكل إعلان
        $reach = collect(Announcement::AUDIENCES)
            ->keys()
            ->mapWithKeys(fn ($audience) => [$audience => (new Announcement(['audience' => $audience]))->reach()]);

        // «إرسال إشعار لكافة …» في الشريط يفتح الصفحة وقد اختير جمهورها
        $chosen = $request->query('audience');

        return view('tenant.announcements.index', [
            'announcements' => $announcements,
            'audiences'     => Announcement::AUDIENCES,
            'reach'         => $reach,
            'audience'      => is_string($chosen) && array_key_exists($chosen, Announcement::AUDIENCES) ? $chosen : 'delivery_couriers',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'audience'   => ['required', 'in:'.implode(',', array_keys(Announcement::AUDIENCES))],
            'title'      => ['required', 'string', 'min:3', 'max:160'],
            'body'       => ['required', 'string', 'min:3', 'max:2000'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:today'],
        ], [], ['audience' => 'الجمهور', 'title' => 'العنوان', 'body' => 'النصّ', 'expires_on' => 'تاريخ الانتهاء']);

        $announcement = $this->announce->publish(
            $data['audience'],
            $data['title'],
            $data['body'],
            $request->user(),
            // «ينتهي يوم كذا» يعني آخر ذلك اليوم لا أوّله
            isset($data['expires_on']) ? Carbon::parse($data['expires_on'])->endOfDay() : null,
        );

        return back()->with('success', "أُرسل «{$announcement->title}» إلى {$announcement->audienceLabel()}.");
    }

    /** مَن قرأ — ومَن لا، فيُتّصل به. */
    public function show(Announcement $announcement): View
    {
        $announcement->load(['author:id,name', 'reads' => fn ($q) => $q->with('user:id,name,phone')->orderBy('read_at')]);

        return view('tenant.announcements.show', [
            'announcement' => $announcement,
            'reach'        => $announcement->reach(),
        ]);
    }
}
