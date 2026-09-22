<?php

namespace App\Actions\Platform;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * دخول مدير المنصّة إلى نظام شركة للدعم.
 *
 * كل دخول يُسجَّل باسم من دخل ومتى وإلى أي شركة، والسجلّ مقروء
 * للشركة نفسها. هذا ليس تفصيلاً أمنياً بل شرط الثقة: شركة تعرف أن
 * أحداً يستطيع فتح بياناتها بلا أثر لن تضع فيها حساباتها.
 */
class ImpersonateCompany
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(Request $request, Company $company): User
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

        $request->session()->put(self::SESSION_KEY, $actor->id);

        // مسار التطوير المحلي حيث لا نطاقات فرعية
        $request->session()->put('dev_company', $company->slug);

        Auth::login($target);

        return $target;
    }

    public function stop(Request $request): ?User
    {
        $impersonatorId = $request->session()->pull(self::SESSION_KEY);

        if (! $impersonatorId) {
            return null;
        }

        $actor = Tenancy::runAsPlatform(fn () => User::find($impersonatorId));

        if (! $actor) {
            Auth::logout();

            return null;
        }

        AuditLog::create([
            'company_id'           => $request->user()?->company_id,
            'user_id'              => $request->user()?->id,
            'user_name'            => $request->user()?->name,
            'impersonator_user_id' => $actor->id,
            'action'               => 'impersonation_ended',
            'ip'                   => $request->ip(),
        ]);

        $request->session()->forget('dev_company');
        Auth::login($actor);

        return $actor;
    }
}
