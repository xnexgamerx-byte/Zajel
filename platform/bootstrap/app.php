<?php

use App\Http\Middleware\EnsurePlatformUser;
use App\Http\Middleware\EnsureMainBranch;
use App\Http\Middleware\EnsureCourier;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsureMerchant;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\IdentifyPlatform;
use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\NormaliseDigits;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ExpiredForm;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // على كل ردّ، صفحةً كان أو ملفاً أو خطأً
        $middleware->append(SecurityHeaders::class);
        // «60 000» و«٦٠٬٠٠٠» تصل 60000، والهاتف 07 وتسعة أرقام — قبل أيّ تحقّق
        $middleware->append(NormaliseDigits::class);

        /*
         | من الوكيل (إن صُدِّق — config/trustedproxy.php) عنوانُ الزائر
         | وبروتوكوله فقط، لا النطاق: النطاق يحدّد الشركة، فيُؤخذ من الطلب
         | نفسه لا من رأسٍ قد يمرّره الوكيل كما كتبه الزائر.
         */
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->appendToGroup('tenant', [
            IdentifyTenant::class,
            EnsureUserBelongsToTenant::class,
        ]);

        // لوحة النواة: بلا شركة، وبلا فلترة company_id
        $middleware->appendToGroup('platform', [IdentifyPlatform::class]);

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
            after: StartSession::class,
            append: IdentifyPlatform::class,
        );

        $middleware->appendToPriorityList(
            after: IdentifyTenant::class,
            append: EnsureUserBelongsToTenant::class,
        );

        $middleware->alias([
            'staff'         => EnsureStaff::class,
            'merchant'      => EnsureMerchant::class,
            'courier'       => EnsureCourier::class,
            'platform-user' => EnsurePlatformUser::class,
            'main-branch'   => EnsureMainBranch::class,
            // ميزةٌ تفتحها المنصّة لكل شركةٍ وحدها: feature:order_reading (docs/plan/35)
            'feature'       => EnsureFeature::class,
        ]);

        // زائر لوحة النواة يُعاد إلى دخولها لا إلى دخول شركة لا وجود لها. ومن أرسل نموذجاً
        // وقد انتهت جلسته يعود إليه بما كتبه بعد الدخول (ExpiredForm)
        $middleware->redirectGuestsTo(function (Request $request) {
            ExpiredForm::stash($request);

            return $request->is('admin', 'admin/*') ? route('admin.login') : route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // نموذجٌ أُرسل بعد انتهاء الجلسة (صفحةٌ بقيت مفتوحةً على الهاتف): يعود إلى صفحته
        // بما كُتب فيه، لا إلى «انتهت صلاحية الصفحة» وزرِّها «الصفحة الرئيسية»
        $exceptions->render(fn (HttpException $e, Request $request) => $e->getPrevious() instanceof TokenMismatchException
            ? ExpiredForm::back($request)
            : null);
    })->create();
