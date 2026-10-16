<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\Courier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCourier
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->role === UserRole::Courier, 403, 'هذه الشاشة للمندوبين.');

        $courier = Courier::find($user->courier_id);

        abort_unless($courier, 403, 'حسابك غير مرتبط بسجلّ مندوب. راجع الشركة.');

        if ($courier->status !== 'active') {
            abort(403, 'حسابك موقوف. راجع الشركة.');
        }

        // الشركة الوسيطة تعمل بمفتاح الربط (docs/plan/64)، لا بتطبيق المندوب (docs/plan/66)
        if ($courier->is_intermediary && $request->is('api/*')) {
            abort(403, 'حساب الشركة الوسيطة يعمل بمفتاح الربط، لا بتطبيق المندوب.');
        }

        $request->attributes->set('courier', $courier);
        view()->share('courier', $courier);

        return $next($request);
    }
}
