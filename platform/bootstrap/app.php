<?php

use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\IdentifyTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('tenant', [
            IdentifyTenant::class,
            EnsureUserBelongsToTenant::class,
        ]);

        /*
         | الترتيب هنا ليس تفصيلاً: لارافيل يرفع وسطاء المصادقة في قائمة
         | الأولوية، فلولا هذا لاستُعلِم عن المستخدم قبل تحديد الشركة —
         | وعندها يرمي CompanyScope بحق. تحديد الشركة يأتي مباشرة بعد بدء
         | الجلسة وقبل أي شيء يمسّ قاعدة البيانات.
         */
        $middleware->appendToPriorityList(
            after: StartSession::class,
            append: IdentifyTenant::class,
        );

        $middleware->appendToPriorityList(
            after: IdentifyTenant::class,
            append: EnsureUserBelongsToTenant::class,
        );

        $middleware->alias(['staff' => EnsureStaff::class]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
