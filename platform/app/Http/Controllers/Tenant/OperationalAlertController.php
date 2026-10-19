<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\FinancialAlert;
use App\Services\Finance\FinancialAlerts;
use App\Services\Operations\OperationalAlerts;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «التنبيهات التشغيلية» (docs/plan/39): ما مرّ عليه آخر موعدٍ للتوصيل ولم يُحسم —
 * المتأخرة بأولويّتها، والمتوقّفة عند نقطة انتقال بمكانها ومسؤولها، والمبالغ التي لم
 * يسلّمها المناديب للمحاسبة. ولمن يرى الأرباح «التنبيهات المالية» (docs/plan/68 §١٢) تبويباً
 * فيها لا شاشةً ثانية.
 */
class OperationalAlertController extends Controller
{
    public const TABS = ['overdue', 'unscanned', 'unsettled', 'finance'];

    public function index(Request $request, OperationalAlerts $alerts, FinancialAlerts $financial): View
    {
        $user = $request->user();
        $money = $user->can('money.view');
        $finance = $user->can('profits.view');

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overdue';
        if (($tab === 'unsettled' && ! $money) || ($tab === 'finance' && ! $finance)) {
            $tab = 'overdue';
        }

        if ($finance) {
            $financial->scanIfStale();
        }
        $financeCounts = $finance ? $financial->counts($user) : [];

        $checkpoints = $alerts->unscannedCounts($user);
        $unsettled = $money ? $alerts->unsettled($user) : collect();

        $data = [
            'tab'         => $tab,
            'hours'       => $alerts->hours(),
            'money'       => $money,
            'finance'     => $finance,
            'checkpoints' => $checkpoints,
            'counts'      => [
                'overdue'   => $alerts->overdue($user)->count(),
                'unscanned' => array_sum($checkpoints),
                'unsettled' => $unsettled->sum('amount'),
                'finance'   => array_sum($financeCounts),
                'critical'  => $financeCounts['critical'] ?? 0,
            ],
        ];

        return view('tenant.operations.alerts', $data + match ($tab) {
            'overdue' => $this->overdue($request, $alerts),
            'unscanned' => $this->unscanned($request, $alerts, $checkpoints),
            'unsettled' => ['branches' => $unsettled->groupBy('branch')],
            'finance' => $this->finance($request, $financeCounts),
        });
    }

    private function overdue(Request $request, OperationalAlerts $alerts): array
    {
        $sort = $request->query('sort') === 'late' ? 'late' : 'priority';

        return [
            'sort'      => $sort,
            'postponed' => $alerts->postponedAhead($request->user()),
            'shipments' => $alerts->overdueRanked($request->user(), $sort)
                ->with(['merchant:id,business_name', 'hub:id,name', 'deliveryCourier:id,name,phone', 'governorate:id,name_ar'])
                ->paginate(50)
                ->withQueryString(),
        ];
    }

    /** @param  array<string, int>  $counts المفتوح بخطورته */
    private function finance(Request $request, array $counts): array
    {
        $status = in_array($request->query('status'), ['active', 'resolved', 'ignored', 'all'], true) ? $request->query('status') : 'active';
        $severity = array_key_exists((string) $request->query('severity'), FinancialAlert::SEVERITIES) ? $request->query('severity') : null;
        $type = array_key_exists((string) $request->query('type'), FinancialAlert::TYPES) ? $request->query('type') : null;

        return [
            'status'   => $status,
            'severity' => $severity,
            'type'     => $type,
            'bySeverity' => $counts,
            'alerts'   => FinancialAlert::query()->visibleTo($request->user())
                ->with(['branch:id,name', 'courier:id,name', 'merchant:id,business_name', 'assignedUser:id,name', 'resolvedBy:id,name', 'actions'])
                ->when($status === 'active', fn ($q) => $q->whereIn('status', ['open', 'in_progress']))
                ->when(in_array($status, ['resolved', 'ignored'], true), fn ($q) => $q->where('status', $status))
                ->when($severity, fn ($q, $s) => $q->where('severity', $s))
                ->when($type, fn ($q, $t) => $q->where('type', $t))
                // الحرج أوّلاً، ثم الأقدم مهلةً
                ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
                ->orderBy('due_at')->orderByDesc('id')
                ->paginate(30)->withQueryString(),
        ];
    }

    /** @param  array<string, int>  $counts */
    private function unscanned(Request $request, OperationalAlerts $alerts, array $counts): array
    {
        $kind = $request->query('kind');

        if (! array_key_exists((string) $kind, OperationalAlerts::CHECKPOINTS)) {
            // أوّل نقطةٍ فيها ما ينتظر، وإلّا الأولى
            $kind = collect($counts)->filter()->keys()->first() ?? array_key_first(OperationalAlerts::CHECKPOINTS);
        }

        return [
            'kind'      => $kind,
            'shipments' => $alerts->unscanned($request->user(), $kind)->paginate(50)->withQueryString(),
        ];
    }
}
