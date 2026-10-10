<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Actions\Notify\Announce;
use App\Actions\Support\Converse;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Conversation;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * التاجر في تطبيقه (docs/plan/59): صورته أو شعار متجره في رأس الرئيسية — يرفعها ويغيّرها
 * ويحذفها — وجرسه: إعلانات الشركة له، وردود الشركة على محادثاته التي لم يقرأها.
 */
class ProfileController extends Controller
{
    /** الصورة على القرص الخاصّ لا العامّ: تُفتح برمزه، ومعها النسخ الاحتياطي للملفّات */
    public static function logoUrl(Merchant $merchant): ?string
    {
        return $merchant->logo_path
            ? route('api.merchant.logo.show', ['v' => substr(md5($merchant->logo_path), 0, 8)])
            : null;
    }

    public function logo(Request $request): StreamedResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        abort_unless($merchant->logo_path && Storage::disk('local')->exists($merchant->logo_path), 404);

        return Storage::disk('local')->response($merchant->logo_path, null, [
            'Cache-Control' => 'private, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ], [
            'logo.required' => 'اختر صورةً أو شعاراً.',
            'logo.mimes' => 'الصورة JPG أو PNG أو WEBP.',
            'logo.max' => 'الصورة أكبر من ٣ ميغابايت — اختر أصغر منها.',
        ]);

        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        $file = $request->file('logo');
        $path = 'merchant-logos/'.$merchant->company_id.'/'.$merchant->id.'-'.Str::uuid().'.'.$file->extension();

        Storage::disk('local')->put($path, $file->get());
        $old = $merchant->logo_path;
        $merchant->forceFill(['logo_path' => $path])->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return response()->json(['logo' => self::logoUrl($merchant), 'message' => 'حُفظت صورتك.']);
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        if ($merchant->logo_path) {
            Storage::disk('local')->delete($merchant->logo_path);
            $merchant->forceFill(['logo_path' => null])->save();
        }

        return response()->json(['logo' => null, 'message' => 'حُذفت الصورة.']);
    }

    /** الجرس: يُفتح فيُقرأ ما فيه من إعلانات — كصندوق البوابة — وردود الدعم تُقرأ في محادثتها */
    public function notifications(Request $request, Announce $announce): JsonResponse
    {
        $user = $request->user();
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $announcements = Announcement::for($user)
            ->with(['reads' => fn ($q) => $q->where('user_id', $user->id)])
            ->latest('id')->limit(50)->get();
        $fresh = $announcements->filter(fn ($a) => $a->reads->isEmpty())->pluck('id')->all();
        $announce->markRead($announcements, $user);

        $replies = Conversation::where('merchant_id', $merchant->id)->where('merchant_unread', true)
            ->where('last_author', Converse::STAFF)->orderByDesc('last_message_at')->limit(20)->get();

        return response()->json([
            'data' => $replies->map(fn (Conversation $c) => [
                'kind' => 'support',
                'id' => $c->id,
                'title' => 'ردّت الشركة: '.$c->subject,
                'body' => 'افتح المحادثة لتقرأ الردّ.',
                'fresh' => true,
                'at' => $c->last_message_at?->toIso8601String(),
            ])->concat($announcements->map(fn (Announcement $a) => [
                'kind' => 'notice',
                'id' => $a->id,
                'title' => $a->title,
                'body' => $a->body,
                'fresh' => in_array($a->id, $fresh, true),
                'at' => $a->created_at?->toIso8601String(),
            ]))->values(),
        ]);
    }
}
