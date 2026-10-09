<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Operations\OperationalAlerts;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «التنبيهات التشغيلية» (docs/plan/39): ما مرّ عليه آخر موعدٍ للتوصيل ولم يُحسم —
 * المتأخرة بأولويّتها، والمتوقّفة عند نقطة انتقال بمكانها ومسؤولها، والمبالغ التي لم
 * يسلّمها المناديب للمحاسبة.
 */
class OperationalAlertController extends Controller
{
    public const TABS = ['overdue', 'unscanned', 'unsettled'];

    public function index(Request $request, OperationalAlerts $alerts): View
    {
        $user = $request->user();
        $money = $user->can('money.view');

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overdue';
        if ($tab === 'unsettled' && ! $money) {
            $tab = 'overdue';
        }

        $checkpoints = $alerts->unscannedCounts($user);
        $unsettled = $money ? $alerts->unsettled($user) : collect();

        $data = [
            'tab'         => $tab,
            'hours'       => $alerts->hours(),
            'money'       => $money,
            'checkpoints' => $checkpoints,
            'counts'      => [
                'overdue'   => $alerts->overdue($user)->count(),
                'unscanned' => array_sum($checkpoints),
                'unsettled' => $unsettled->sum('amount'),
            ],
        ];

        return view('tenant.operations.alerts', $data + match ($tab) {
            'overdue' => $this->overdue($request, $alerts),
            'unscanned' => $this->unscanned($request, $alerts, $checkpoints),
            'unsettled' => ['branches' => $unsettled->groupBy('branch')],
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
