<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\SetCompanyFeature;
use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Support\StaffNavigation;
use App\Support\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * نظام كل شركةٍ من لوحة المنصّة (docs/plan/35): ميزاته التي تُفتح وتُغلق برسمها الشهريّ،
 * ومظهره، وترتيب قوائم موظّفيه. لكل شركةٍ نظامها: لا يمسّ قرارٌ هنا شركةً أخرى.
 */
class CompanySystemController extends Controller
{
    public function show(Company $company): View
    {
        return view('platform.companies.system', [
            'company'  => $company,
            'features' => $company->featureStates(),
            // «من لون الشعار» بلا لونٍ صالح يصير مرجانياً: لا يُعرض مرّتين
            'themes'   => collect(Theme::NAMES)->keys()->map(fn (string $key) => Theme::make($key, $company->primary_color))
                ->unique('key')->values()->all(),
            'theme'    => $company->theme(),
            'menus'    => StaffNavigation::ordered($company),
            'arranged' => is_array($company->setting('navigation')),
        ]);
    }

    /** تفتح الميزة أو تُغلق، برسمها الشهريّ — من صفحة الشركة ومن صفحة الميزة */
    public function feature(Request $request, Company $company, SetCompanyFeature $set): RedirectResponse
    {
        $data = $request->validate([
            'feature'       => ['required', Rule::enum(Feature::class)],
            'enabled'       => ['required', 'boolean'],
            'monthly_price' => ['nullable', 'integer', 'min:0', 'max:100000000', 'multiple_of:250'],
        ], [
            'monthly_price.multiple_of' => 'الرسم الشهري بمضاعفات ٢٥٠ دينار.',
        ], [
            'feature' => 'الميزة', 'enabled' => 'الحالة', 'monthly_price' => 'الرسم الشهري',
        ]);

        $feature = Feature::from($data['feature']);
        $enabled = (bool) $data['enabled'];
        $price = $enabled ? (int) ($data['monthly_price'] ?? 0) : 0;
        $was = $company->hasFeature($feature);

        if (! $set->handle($company, $feature, $enabled, $price, $request->user(), $request->ip())) {
            return back()->with('success', 'لم يتغيّر شيء.');
        }

        $fee = $price ? 'بـ '.number_format($price).' د.ع شهرياً تُضاف إلى فاتورتها' : 'مجّاناً';

        return back()->with('success', match (true) {
            ! $enabled => "أُغلقت «{$feature->label()}» في نظام {$company->name}.",
            ! $was     => "فُتحت «{$feature->label()}» لشركة {$company->name} {$fee}.",
            default    => "صار رسم «{$feature->label()}» لشركة {$company->name}: {$fee}.",
        });
    }

    public function theme(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(array_keys(Theme::NAMES))],
        ], [], ['theme' => 'المظهر']);

        $old = $company->theme()->key;

        if ($old === $data['theme']) {
            return redirect()->to(route('admin.companies.system', $company).'#theme')->with('success', 'لم يتغيّر شيء.');
        }

        $company->forceFill(['settings' => ['theme' => $data['theme']] + ($company->settings ?? [])])->save();

        AuditLog::create([
            'company_id'     => $company->id,
            'user_id'        => $request->user()->id,
            'user_name'      => $request->user()->name,
            'action'         => 'theme_changed',
            'auditable_type' => Company::class,
            'auditable_id'   => $company->id,
            'old_values'     => ['theme' => $old],
            'new_values'     => ['theme' => $data['theme']],
            'ip'             => $request->ip(),
        ]);

        return redirect()->to(route('admin.companies.system', $company).'#theme')
            ->with('success', "صار مظهر نظام {$company->name}: ".Theme::NAMES[$data['theme']].'.');
    }

    /**
     * ترتيب قوائم موظّفي الشركة: كل ضغطةٍ تنقل قائمةً أو رابطاً في قائمته خطوةً، وتُحفظ.
     * move: «menu|<القائمة>|up» أو «link|<القائمة>|<الرابط>|down». وreset يعيد الأصل.
     */
    public function navigation(Request $request, Company $company): RedirectResponse
    {
        $settings = $company->settings ?? [];
        $back = route('admin.companies.system', $company).'#menus';

        if ($request->boolean('reset')) {
            unset($settings['navigation']);
            $company->forceFill(['settings' => $settings])->save();

            return redirect()->to($back)->with('success', 'عادت قوائم موظّفي الشركة إلى ترتيبها الأصليّ.');
        }

        $move = explode('|', (string) $request->input('move'));
        $direction = end($move);
        $menus = StaffNavigation::ordered($company);
        $navigation = is_array($settings['navigation'] ?? null) ? $settings['navigation'] : [];

        if (! in_array($direction, ['up', 'down'], true) || ! isset($menus[$move[1] ?? ''])) {
            throw ValidationException::withMessages(['move' => 'نقلٌ غير معروف.']);
        }

        if ($move[0] === 'menu' && count($move) === 3) {
            $navigation['menus'] = self::shift(array_keys($menus), $move[1], $direction);
        } elseif ($move[0] === 'link' && count($move) === 4) {
            $links = array_map(StaffNavigation::linkKey(...), $menus[$move[1]][2]);

            if (! in_array($move[2], $links, true)) {
                throw ValidationException::withMessages(['move' => 'نقلٌ غير معروف.']);
            }

            $navigation['links'][$move[1]] = self::shift($links, $move[2], $direction);
        } else {
            throw ValidationException::withMessages(['move' => 'نقلٌ غير معروف.']);
        }

        $settings['navigation'] = $navigation;
        $company->forceFill(['settings' => $settings])->save();

        // الرابط داخل قائمته: تبقى قائمته مفتوحةً بعد الحفظ
        return redirect()->to($move[0] === 'link' ? route('admin.companies.system', $company).'#menu-'.$move[1] : $back)
            ->with('opened', $move[0] === 'link' ? $move[1] : null);
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private static function shift(array $keys, string $key, string $direction): array
    {
        $from = array_search($key, $keys, true);
        $to = $direction === 'up' ? $from - 1 : $from + 1;

        if ($from !== false && isset($keys[$to])) {
            [$keys[$from], $keys[$to]] = [$keys[$to], $keys[$from]];
        }

        return $keys;
    }
}
