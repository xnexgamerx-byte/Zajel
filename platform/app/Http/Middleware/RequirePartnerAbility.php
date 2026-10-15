<?php

namespace App\Http\Middleware;

use App\Models\CourierApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** يتطلب أن يحمل مفتاح الشريك صلاحيةً بعينها: partner.can:shipments.status */
class RequirePartnerAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        /** @var CourierApiKey $key */
        $key = $request->attributes->get('partner_key');

        if ($key === null || ! $key->allows($ability)) {
            return response()->json(['message' => 'لهذا المفتاح لا صلاحية لهذا الإجراء.'], 403);
        }

        return $next($request);
    }
}
