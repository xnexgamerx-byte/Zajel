<?php

namespace App\Actions\Platform;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * دخول مدير المنصّة إلى نظام شركة للدعم.
 *
 * كل دخول يُسجَّل باسم من دخل ومتى وإلى أي شركة، والسجلّ مقروء
 * للشركة نفسها. هذا ليس تفصيلاً أمنياً بل شرط الثقة: شركة تعرف أن
 * أحداً يستطيع فتح بياناتها بلا أثر لن تضع فيها حساباتها.
 *
 * الدخول تذكرةٌ تنتقل من نطاقٍ إلى نطاق، لا جلسة.
 *
 * لوحة المنصّة على admin.{النطاق} والشركة على {الشركة}.{النطاق}، وكعكة
 * الجلسة لكل نطاقٍ وحده. فكان الدخول يُسجِّل صاحب الشركة في جلسة لوحة
 * المنصّة ثم يفتح /shipments على نطاقها — 404 في الإنتاج، والمدير خرج
 * من لوحته ولم يدخل الشركة. ولم يظهر في تطويرٍ ولا اختبار لأن الشركة
 * هناك تُعرَف من الجلسة، وهو طريقٌ مغلق في الإنتاج عمداً.
 *
 * الآن: لوحة المنصّة تكتب تذكرةً لمرّةٍ واحدة تعيش دقيقة، ونطاق الشركة
 * يصرفها فيفتح جلسته هو. وجلسة لوحة المنصّة لا تُمسّ.
 */
class ImpersonateCompany
{
    public const SESSION_KEY = 'impersonator_id';

    /** عمر التذكرة: تكفي لتحويلٍ واحد، ولا تكفي لمن يلتقط الرابط لاحقاً. */
    public const TICKET_SECONDS = 60;

    /** يكتب التذكرة ويُرجع عنوان صرفها على نطاق الشركة. */
    public function start(Request $request, Company $company): string
    {
        $actor = $request->user();

        if (! $actor?->isPlatformUser()) {
            throw ValidationException::withMessages(['company' => 'غير مصرّح.']);
        }

        $target = Tenancy::runFor($company, fn () => User::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::CompanyOwner->value, UserRole::CompanyAdmin->value])
            ->orderBy('id')
            ->first());

        if (! $target) {
            throw ValidationException::withMessages([
                'company' => "لا يوجد حساب إداري مفعّل في {$company->name}.",
            ]);
        }

        AuditLog::create([
            'company_id'          => $company->id,
            'user_id'             => $target->id,
            'user_name'           => $target->name,
            'impersonator_user_id' => $actor->id,
            'action'              => 'impersonation_started',
            'auditable_type'      => Company::class,
            'auditable_id'        => $company->id,
            'new_values'          => ['by' => $actor->name, 'as' => $target->name],
            'ip'                  => $request->ip(),
            'user_agent'          => substr((string) $request->userAgent(), 0, 255),
        ]);

        $token = Str::random(64);

        Cache::put($this->ticketKey($token), [
            'company_id'      => $company->id,
            'user_id'         => $target->id,
            'impersonator_id' => $actor->id,
        ], self::TICKET_SECONDS);

        return $this->companyUrl($request, $company, '/impersonate/'.$token);
    }

    /**
     * يصرف التذكرة على نطاق الشركة. null إن لم تكن تذكرةً صالحةً لهذه
     * الشركة الآن — والتذكرة تُسحَب قبل أي فحص، فلا تُصرَف مرّتين ولو
     * فشلت الأولى.
     */
    public function enter(Request $request, string $token): ?User
    {
        $ticket = Cache::pull($this->ticketKey($token));

        if (! is_array($ticket) || (int) $ticket['company_id'] !== Tenancy::id()) {
            return null;
        }

        $target = User::query()->where('is_active', true)->find($ticket['user_id']);

        if (! $target) {
            return null;
        }

        Auth::login($target);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, (int) $ticket['impersonator_id']);

        return $target;
    }

    /** يُنهي الدخول على نطاق الشركة ويُرجع عنوان لوحة المنصّة، أو null إن لم يكن دخولاً. */
    public function stop(Request $request): ?string
    {
        $impersonatorId = $request->session()->pull(self::SESSION_KEY);

        if (! $impersonatorId) {
            return null;
        }

        AuditLog::create([
            'company_id'           => $request->user()?->company_id,
            'user_id'              => $request->user()?->id,
            'user_name'            => $request->user()?->name,
            'impersonator_user_id' => $impersonatorId,
            'action'               => 'impersonation_ended',
            'ip'                   => $request->ip(),
        ]);

        // جلسة الشركة تنتهي هنا؛ جلسة المدير على نطاق المنصّة لم تُمسّ
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->platformUrl($request, '/admin');
    }

    protected function ticketKey(string $token): string
    {
        // المفتاح بصمة التذكرة لا التذكرة: ما في ذاكرة التخزين لا يُفتح به شيء
        return 'impersonation:'.hash('sha256', $token);
    }

    /**
     * عنوان على نطاق الشركة بمخطّط الطلب الحالي ومنفذه. وفي التطوير بلا
     * نطاقاتٍ فرعية (localhost) المضيفُ نفسه و?company= كما يقرؤه
     * IdentifyTenant هناك.
     */
    protected function companyUrl(Request $request, Company $company, string $path): string
    {
        if (! $base = $this->baseDomain($request)) {
            return $request->getSchemeAndHttpHost().$path.'?company='.urlencode($company->slug);
        }

        return $this->hostUrl($request, $company->slug.'.'.$base).$path;
    }

    protected function platformUrl(Request $request, string $path): string
    {
        if (! $base = $this->baseDomain($request)) {
            return $request->getSchemeAndHttpHost().$path;
        }

        return $this->hostUrl($request, 'admin.'.$base).$path;
    }

    /** النطاق الأساسي إن كان الطلب على أحد نطاقاته الفرعية، وإلا null. */
    protected function baseDomain(Request $request): ?string
    {
        $base = (string) config('zajel.tenant_domain');

        return $base !== '' && str_ends_with($request->getHost(), '.'.$base) ? $base : null;
    }

    protected function hostUrl(Request $request, string $host): string
    {
        $port = $request->getPort();
        $default = $request->isSecure() ? 443 : 80;

        return $request->getScheme().'://'.$host.($port && (int) $port !== $default ? ':'.$port : '');
    }
}
