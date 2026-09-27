<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AppAd;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «إعلانات الصفحة الرئيسية بالتطبيق»: صورٌ بترتيبها أعلى بوابة التاجر وتطبيق
 * المندوب — عرضٌ، أو تنبيهٌ بتغيير أسعار، أو رقم فرعٍ جديد.
 */
class AppAdController extends Controller
{
    public function index(): View
    {
        return view('tenant.app-ads.index', [
            'ads' => AppAd::orderBy('audience')->orderBy('sort_order')->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title'      => ['required', 'string', 'max:120'],
            // صورةٌ حقيقية لا SVG: ملفٌّ يُعرض لكل مستخدمي الشركة لا يحمل نصّاً يُنفَّذ
            'image'      => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'link_url'   => ['nullable', 'url:http,https', 'max:255'],
            'audience'   => ['required', Rule::in(array_keys(AppAd::AUDIENCES))],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], [], ['title' => 'العنوان', 'image' => 'الصورة', 'link_url' => 'الرابط', 'audience' => 'لمن']);

        $path = $request->file('image')->store('ads/'.$request->user()->company_id, 'local');

        AppAd::create([
            'title'              => $data['title'],
            'image_path'         => $path,
            'link_url'           => $data['link_url'] ?? null,
            'audience'           => $data['audience'],
            'sort_order'         => (int) ($data['sort_order'] ?? 0),
            'is_active'          => true,
            'created_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'أُضيف الإعلان، ويظهر الآن '.AppAd::SHOWN_TO[$data['audience']].'.');
    }

    public function update(Request $request, AppAd $ad): RedirectResponse
    {
        $data = $request->validate([
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active'  => ['nullable', 'boolean'],
        ]);

        $ad->update([
            'sort_order' => (int) ($data['sort_order'] ?? $ad->sort_order),
            'is_active'  => $request->boolean('is_active'),
        ]);

        return back()->with('success', $ad->is_active ? "«{$ad->title}» يظهر." : "«{$ad->title}» أُوقف.");
    }

    public function destroy(AppAd $ad): RedirectResponse
    {
        Storage::disk('local')->delete($ad->image_path);
        $ad->delete();

        return back()->with('success', "حُذف الإعلان «{$ad->title}».");
    }

    /**
     * الصورة لمستخدمي الشركة وحدهم (ربط المسار يفلتر بالشركة)، والتاجر
     * والمندوب لا يريان إلا ما يُعرض عليهما الآن.
     */
    public function image(Request $request, AppAd $ad): StreamedResponse
    {
        $user = $request->user();

        if (! $user->isStaff()) {
            $audience = $user->role === \App\Enums\UserRole::Courier ? 'couriers' : 'merchants';
            abort_unless(AppAd::shownTo($audience)->whereKey($ad->id)->exists(), 404);
        }

        abort_unless(Storage::disk('local')->exists($ad->image_path), 404);

        return Storage::disk('local')->response($ad->image_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}
