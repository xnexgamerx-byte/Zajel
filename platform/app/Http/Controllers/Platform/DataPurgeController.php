<?php

namespace App\Http\Controllers\Platform;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Services\Platform\CompanyDataPurge;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * «حذف البيانات» في لوحة النواة (docs/plan/102): شركةٌ، وما يُحذف منها، ومدّته. ثلاث خطوات:
 * الاختيار، ثم ما سيُحذف بأعداده، ثم التأكيد باسم الشركة المختصر وكلمة مرور مدير المنصّة.
 * لمدير المنصّة وحده — دعم المنصّة يرى ولا يحذف.
 */
class DataPurgeController extends Controller
{
    public function index(Request $request): View
    {
        return view('platform.purge.index', [
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'parts' => CompanyDataPurge::PARTS,
            'chosen' => $request->integer('company') ?: null,
            'canPurge' => $this->canPurge($request),
        ]);
    }

    public function preview(Request $request): View
    {
        abort_unless($this->canPurge($request), 403);
        [$company, $parts, $from, $to] = $this->chosen($request);

        return view('platform.purge.preview', [
            'company' => $company,
            'parts' => $parts,
            'mode' => $request->input('mode'),
            'from' => $from,
            'to' => $to,
            'rows' => (new CompanyDataPurge($company, $from, $to))->preview($parts),
            'labels' => CompanyDataPurge::PARTS,
            'period' => self::period($from, $to),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        abort_unless($this->canPurge($request), 403);
        [$company, $parts, $from, $to] = $this->chosen($request);

        $request->validate([
            'confirm_slug' => ['required', 'string'],
            'password' => ['required', 'string'],
            'understood' => ['accepted'],
        ], [
            'understood.accepted' => 'أكّد أنّ الحذف لا يُسترجع.',
        ], [
            'confirm_slug' => 'اسم الشركة المختصر', 'password' => 'كلمة المرور',
        ]);

        if (trim((string) $request->input('confirm_slug')) !== $company->slug) {
            throw ValidationException::withMessages(['confirm_slug' => "اكتب «{$company->slug}» كما هو حتى يُحذف."]);
        }

        if (! Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'كلمة المرور غير صحيحة.']);
        }

        $counts = (new CompanyDataPurge($company, $from, $to))->run($parts);

        AuditLog::create([
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'action' => 'data_purged',
            'auditable_type' => Company::class,
            'auditable_id' => $company->id,
            'new_values' => ['parts' => $parts, 'from' => $from?->toDateString(), 'to' => $to?->toDateString(), 'counts' => $counts],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.purge.index', ['company' => $company->id])
            ->with('purged', ['company' => $company->name, 'period' => self::period($from, $to), 'counts' => $counts]);
    }

    public static function period(?CarbonImmutable $from, ?CarbonImmutable $to): string
    {
        return match (true) {
            $from === null => 'كل الوقت',
            $from->eq($to) => 'يوم '.$from->toDateString(),
            default => 'من '.$from->toDateString().' إلى '.$to->toDateString(),
        };
    }

    private function canPurge(Request $request): bool
    {
        return $request->user()?->role === UserRole::PlatformAdmin;
    }

    /** @return array{0: Company, 1: list<string>, 2: ?CarbonImmutable, 3: ?CarbonImmutable} */
    private function chosen(Request $request): array
    {
        // «كلّ شيء» يختار الأجزاء كلّها
        if ($request->boolean('everything')) {
            $request->merge(['parts' => array_keys(CompanyDataPurge::PARTS)]);
        }

        $data = $request->validate([
            'company' => ['required', 'integer', Rule::exists('companies', 'id')],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*' => [Rule::in(array_keys(CompanyDataPurge::PARTS))],
            'mode' => ['required', Rule::in(['all', 'day', 'range'])],
            'day' => ['required_if:mode,day', 'nullable', 'date_format:Y-m-d'],
            'from' => ['required_if:mode,range', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:mode,range', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'parts.required' => 'اختر ما يُحذف.',
        ], [
            'company' => 'الشركة', 'parts' => 'ما يُحذف', 'mode' => 'المدّة', 'day' => 'اليوم', 'from' => 'من', 'to' => 'إلى',
        ]);

        [$from, $to] = match ($data['mode']) {
            'all' => [null, null],
            'day' => [CarbonImmutable::parse($data['day']), CarbonImmutable::parse($data['day'])],
            'range' => [CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to'])],
        };

        // بترتيب الشاشة، بلا تكرار
        $parts = array_values(array_intersect(array_keys(CompanyDataPurge::PARTS), $data['parts']));

        return [Company::findOrFail($data['company']), $parts, $from, $to];
    }
}
