<?php

namespace App\Http\Middleware;

use App\Support\FeatureGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * شاشات ميزةٍ تفتحها المنصّة لكل شركةٍ وحدها (feature:<المفتاح>، docs/plan/35): في
 * شركةٍ أُغلقت فيها يغيب رابطها (FeatureGate يقرأ هذا الحاجز)، ومن فتحها بعنوانها يُرَدّ.
 */
class EnsureFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(FeatureGate::enabled(FeatureGate::known($feature)), 403,
            'هذه الميزة غير مفعّلة في نظام شركتكم — يفعّلها صاحب الشركة بطلبٍ إلى إدارة المنصّة.');

        return $next($request);
    }
}
