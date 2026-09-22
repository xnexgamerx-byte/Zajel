<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * بوابة التاجر. تُحمّل سجلّ التاجر مرّة واحدة وتشاركه مع كل الشاشات،
 * وترفض حساباً بلا سجلّ أو لتاجر موقوف — فحساب يدخل إلى بوابة لا
 * تخصّه أسوأ من حساب لا يدخل.
 */
class EnsureMerchant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->role === UserRole::Merchant, 403, 'هذه البوابة للتجّار.');

        $merchant = Merchant::find($user->merchant_id);

        abort_unless($merchant, 403, 'حسابك غير مرتبط بمتجر. راجع شركة التوصيل.');

        if ($merchant->status === 'suspended') {
            abort(403, 'حساب متجرك موقوف لدى شركة التوصيل.');
        }

        $request->attributes->set('merchant', $merchant);
        view()->share('merchant', $merchant);

        return $next($request);
    }
}
