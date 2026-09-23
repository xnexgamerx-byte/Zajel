<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Phone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * بيانات الشركة التي يراها تجّارها ومناديبها.
 *
 * الاسم والنطاق الفرعي ليسا هنا: هما هويّة الاشتراك والفوترة، وتغييرهما
 * من لوحة المنصّة. أما الهاتف وواتساب الدعم واللون فللشركة أن تُديرها.
 */
class CompanySettingsController extends Controller
{
    public function edit(TenantContext $tenant): View
    {
        return view('tenant.settings.company', ['company' => $tenant->company()]);
    }

    public function update(Request $request, TenantContext $tenant): RedirectResponse
    {
        $company = $tenant->company();

        // ما لا يُفهم رقماً عراقياً يُرَدّ، لا يُحفظ كما كُتب
        $iraqi = function (string $attribute, mixed $value, \Closure $fail) {
            if (Phone::normalise((string) $value) === null) {
                $fail('رقمٌ عراقي بصيغة 07xxxxxxxxx.');
            }
        };

        $data = $request->validate([
            'phone'            => ['nullable', 'string', 'max:30', $iraqi],
            'email'            => ['nullable', 'email', 'max:160'],
            'support_whatsapp' => ['nullable', 'string', 'max:30', $iraqi],
            'support_hours'    => ['nullable', 'string', 'max:120'],
            'primary_color'    => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'primary_color.regex' => 'اللون بصيغة #RRGGBB.',
        ], [
            'phone' => 'الهاتف', 'email' => 'البريد', 'support_whatsapp' => 'واتساب الدعم',
            'support_hours' => 'ساعات الدعم', 'primary_color' => 'اللون',
        ]);

        // والرقم يُحفظ بصيغةٍ واحدة أيّاً كان شكل إدخاله
        $phones = [
            'phone'            => Phone::normalise($data['phone'] ?? null),
            'support_whatsapp' => Phone::normalise($data['support_whatsapp'] ?? null),
        ];

        $before = [
            'phone'            => $company->phone,
            'email'            => $company->email,
            'primary_color'    => $company->primary_color,
            'support_whatsapp' => $company->setting('support.whatsapp'),
            'support_hours'    => $company->setting('support.hours'),
        ];

        $settings = $company->settings ?? [];
        data_set($settings, 'support.whatsapp', $phones['support_whatsapp']);
        data_set($settings, 'support.hours', filled($data['support_hours'] ?? null) ? trim($data['support_hours']) : null);

        $company->forceFill([
            'phone'         => $phones['phone'],
            'email'         => $data['email'] ?? null,
            'primary_color' => strtoupper($data['primary_color']),
            'settings'      => $settings,
        ])->save();

        $after = [
            'phone'            => $company->phone,
            'email'            => $company->email,
            'primary_color'    => $company->primary_color,
            'support_whatsapp' => $company->setting('support.whatsapp'),
            'support_hours'    => $company->setting('support.hours'),
        ];

        // ما تغيّر وحده، وبمَن غيّره: رقمُ دعمٍ تبدّل يُسأل عنه يوم يشكو تاجر
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

        if ($changed) {
            AuditLog::create([
                'company_id' => $company->id,
                'user_id'    => $request->user()->id,
                'user_name'  => $request->user()->name,
                'action'     => 'company_settings_updated',
                'old_values' => array_intersect_key($before, array_flip($changed)),
                'new_values' => array_intersect_key($after, array_flip($changed)),
                'ip'         => $request->ip(),
            ]);
        }

        return back()->with('success', $changed ? 'حُفظت بيانات الشركة.' : 'لم يتغيّر شيء.');
    }
}
