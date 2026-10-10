<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Merchant;
use App\Support\DeliveryDeadline;
use App\Support\MerchantHours;
use App\Support\MerchantNotice;
use App\Support\PayoutMethods;
use App\Support\Phone;
use App\Support\ShipmentFields;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            'phone' => ['nullable', 'string', 'max:30', $iraqi],
            'email' => ['nullable', 'email', 'max:160'],
            'support_whatsapp' => ['nullable', 'string', 'max:30', $iraqi],
            'support_complaints' => ['nullable', 'string', 'max:30', $iraqi],
            'support_hours' => ['nullable', 'string', 'max:120'],
            'waybill_terms' => ['nullable', 'string', 'max:600'],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            // ما تُلزِم به الشركة من حقول الشحنة الاختياريّة (docs/plan/38)
            'shipment_required' => ['nullable', 'array'],
            'shipment_required.*' => ['string', Rule::in(array_keys(ShipmentFields::CHOOSABLE))],
            // ساعات مراسلة التاجر وآخر موعدٍ للتوصيل (docs/plan/39)
            'merchant_from' => ['nullable', 'integer', 'between:0,23'],
            'merchant_to' => ['nullable', 'integer', 'between:1,24'],
            'deadline_hours' => ['nullable', 'integer', 'between:1,240'],
            // طرق دفع مستحقّات التجّار المعروضة في «طلب محاسبة» (docs/plan/44)
            'payout_offered' => ['sometimes', 'array', 'min:1'],
            'payout_offered.*' => ['string', Rule::in(array_keys(Merchant::PAYOUT_METHODS))],
            // إشعار التاجر بتغيّر مبلغ وصله (docs/plan/61)
            'notify_amount_change' => ['sometimes', 'boolean'],
        ], [
            'payout_offered.min' => 'اترك طريقة دفعٍ واحدةً على الأقل للتجّار.',
            'primary_color.regex' => 'اللون بصيغة #RRGGBB.',
        ], [
            'phone' => 'الهاتف', 'email' => 'البريد', 'support_whatsapp' => 'واتساب الدعم', 'support_complaints' => 'هاتف الشكاوى',
            'support_hours' => 'ساعات الدعم', 'waybill_terms' => 'شروط الوصل المطبوع', 'primary_color' => 'اللون',
            'merchant_from' => 'بداية ساعات المراسلة', 'merchant_to' => 'نهاية ساعات المراسلة', 'deadline_hours' => 'آخر موعد للتوصيل',
        ]);

        // لا طريقة دفعٍ للتجّار تُترك فارغة: التاجر لا يطلب حسابه بلا طريقة
        if ($request->boolean('payout_form') && empty($data['payout_offered'])) {
            throw ValidationException::withMessages(['payout_offered' => 'اترك طريقة دفعٍ واحدةً على الأقل للتجّار.']);
        }

        // النموذج يرسل الساعتين معاً؛ وما لم يُرسَل منهما يبقى كما كان
        $from = $data['merchant_from'] ?? MerchantHours::from($company);
        $to = $data['merchant_to'] ?? MerchantHours::to($company);

        if ($to <= $from) {
            throw ValidationException::withMessages(['merchant_to' => 'نهاية ساعات المراسلة بعد بدايتها.']);
        }

        // والرقم يُحفظ بصيغةٍ واحدة أيّاً كان شكل إدخاله
        $phones = [
            'phone' => Phone::normalise($data['phone'] ?? null),
            'support_whatsapp' => Phone::normalise($data['support_whatsapp'] ?? null),
            'support_complaints' => Phone::normalise($data['support_complaints'] ?? null),
        ];

        $before = [
            'phone' => $company->phone,
            'email' => $company->email,
            'primary_color' => $company->primary_color,
            'support_whatsapp' => $company->setting('support.whatsapp'),
            'support_complaints' => $company->setting('support.complaints'),
            'support_hours' => $company->setting('support.hours'),
            'waybill_terms' => $company->setting('waybill.terms'),
            'shipment_required' => implode(',', ShipmentFields::required($company)),
            'merchant_hours' => MerchantHours::window($company),
            'deadline_hours' => DeliveryDeadline::hours($company),
            'payout_offered' => implode(',', array_keys(PayoutMethods::offered($company))),
            'notify_amount_change' => MerchantNotice::amountChangeEnabled($company) ? '1' : '0',
        ];

        $settings = $company->settings ?? [];
        data_set($settings, 'shipment.required', array_values(array_unique($data['shipment_required'] ?? [])));
        data_set($settings, 'support.whatsapp', $phones['support_whatsapp']);
        data_set($settings, 'support.complaints', $phones['support_complaints']);
        data_set($settings, 'support.hours', filled($data['support_hours'] ?? null) ? trim($data['support_hours']) : null);
        data_set($settings, 'support.merchant_from', (int) $from);
        data_set($settings, 'support.merchant_to', (int) $to);
        if (isset($data['payout_offered'])) {
            data_set($settings, 'payout.disabled', array_values(array_diff(array_keys(Merchant::PAYOUT_METHODS), $data['payout_offered'])));
        }
        if (array_key_exists('notify_amount_change', $data)) {
            data_set($settings, 'notify.amount_change', (bool) $data['notify_amount_change']);
        }
        if (isset($data['deadline_hours'])) {
            data_set($settings, 'delivery.deadline_hours', (int) $data['deadline_hours']);
        }
        // شروط الوصل المطبوع: سطرٌ لكلّ شرط، بلا أسطرٍ فارغة (WaybillBook::terms)
        $terms = collect(preg_split('/\R/u', (string) ($data['waybill_terms'] ?? '')))->map(fn ($line) => trim($line))->filter()->implode("\n");
        data_set($settings, 'waybill.terms', $terms === '' ? null : $terms);

        $company->forceFill([
            'phone' => $phones['phone'],
            'email' => $data['email'] ?? null,
            'primary_color' => strtoupper($data['primary_color']),
            'settings' => $settings,
        ])->save();

        $after = [
            'phone' => $company->phone,
            'email' => $company->email,
            'primary_color' => $company->primary_color,
            'support_whatsapp' => $company->setting('support.whatsapp'),
            'support_complaints' => $company->setting('support.complaints'),
            'support_hours' => $company->setting('support.hours'),
            'waybill_terms' => $company->setting('waybill.terms'),
            'shipment_required' => implode(',', ShipmentFields::required($company)),
            'merchant_hours' => MerchantHours::window($company),
            'deadline_hours' => DeliveryDeadline::hours($company),
            'payout_offered' => implode(',', array_keys(PayoutMethods::offered($company))),
            'notify_amount_change' => MerchantNotice::amountChangeEnabled($company) ? '1' : '0',
        ];

        // ما تغيّر وحده، وبمَن غيّره: رقمُ دعمٍ تبدّل يُسأل عنه يوم يشكو تاجر
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

        if ($changed) {
            AuditLog::create([
                'company_id' => $company->id,
                'user_id' => $request->user()->id,
                'user_name' => $request->user()->name,
                'action' => 'company_settings_updated',
                'old_values' => array_intersect_key($before, array_flip($changed)),
                'new_values' => array_intersect_key($after, array_flip($changed)),
                'ip' => $request->ip(),
            ]);
        }

        return back()->with('success', $changed ? 'حُفظت بيانات الشركة.' : 'لم يتغيّر شيء.');
    }
}
