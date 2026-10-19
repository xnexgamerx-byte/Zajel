<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * الإعدادات المالية (docs/plan/68 §١٣): الاعتماد المزدوج والاحتياطي ودورة التجّار وحدّ السحب،
 * وعتبات التنبيهات والإقفال. صفحةٌ لصاحب الشركة (finance.settings) منفصلةٌ عن بياناتها، وكل
 * تغييرٍ فيها بقيمته قبل وبعد في سجلّ التدقيق.
 */
class FinanceSettingsController extends Controller
{
    /** المفتاح: [القاعدة، الافتراضي، الاسم] */
    public const FIELDS = [
        'reserve_fixed'            => [['nullable', 'integer', 'min:0', 'max:100000000000'], null, 'الاحتياطي الثابت'],
        'reserve_days'             => [['nullable', 'integer', 'between:0,90'], null, 'أيام الاحتياطي'],
        'merchant_cycle_days'      => [['nullable', 'integer', 'between:1,60'], 7, 'دورة تسوية التجّار'],
        'withdrawal_approval_over' => [['nullable', 'integer', 'min:0', 'max:100000000000'], null, 'حدّ اعتماد السحب'],
        'custody_age_days'         => [['nullable', 'integer', 'between:1,60'], 7, 'حدّ عمر العهدة'],
        'cash_handover_hours'      => [['nullable', 'integer', 'between:1,240'], 24, 'مهلة تسليم المندوب نقده'],
        'batch_match_hours'        => [['nullable', 'integer', 'between:1,240'], 24, 'مهلة مطابقة الوجبة'],
        'min_liquidity'            => [['nullable', 'integer', 'min:0', 'max:100000000000'], null, 'الحدّ الأدنى للسيولة المتوقَّعة'],
        'cost_ratio_max'           => [['nullable', 'integer', 'between:1,1000'], 90, 'أعلى نسبةٍ للتكاليف من الإيراد'],
        'margin_min'               => [['nullable', 'integer', 'between:-100,100'], 0, 'أدنى هامش ربح'],
    ];

    public function edit(TenantContext $tenant): View
    {
        return view('tenant.finance.settings', ['settings' => $this->current($tenant)]);
    }

    public function update(Request $request, TenantContext $tenant): RedirectResponse
    {
        $data = $request->validate(
            ['dual_approval' => ['sometimes', 'boolean']] + array_map(fn ($f) => $f[0], self::FIELDS),
            [],
            ['dual_approval' => 'الاعتماد المزدوج'] + array_map(fn ($f) => $f[2], self::FIELDS),
        );

        $company = $tenant->company();
        $before = $this->current($tenant);

        $settings = $company->settings ?? [];
        foreach (array_keys(self::FIELDS) as $key) {
            if (array_key_exists($key, $data)) {
                data_set($settings, "finance.{$key}", $data[$key] === null ? null : (int) $data[$key]);
            }
        }
        if (array_key_exists('dual_approval', $data)) {
            data_set($settings, 'finance.dual_approval', (bool) $data['dual_approval']);
        }
        $company->forceFill(['settings' => $settings])->save();

        $after = $this->current($tenant);
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

        if ($changed) {
            AuditLog::create([
                'user_id'    => $request->user()->id,
                'user_name'  => $request->user()->name,
                'action'     => 'finance_settings_updated',
                'old_values' => array_intersect_key($before, array_flip($changed)),
                'new_values' => array_intersect_key($after, array_flip($changed)),
                'ip'         => $request->ip(),
            ]);
        }

        return back()->with('success', $changed ? 'حُفظت الإعدادات المالية.' : 'لم يتغيّر شيء.');
    }

    /** @return array<string, int|string|null> القيم كما تُقرأ، بافتراضيّها */
    protected function current(TenantContext $tenant): array
    {
        $company = $tenant->company();
        $values = ['dual_approval' => $company->setting('finance.dual_approval') ? '1' : '0'];

        foreach (self::FIELDS as $key => [, $default]) {
            $value = $company->setting("finance.{$key}");
            $values[$key] = $value === null ? $default : (int) $value;
        }

        return $values;
    }
}
