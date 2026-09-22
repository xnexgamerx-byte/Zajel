<?php

namespace App\Http\Controllers\Platform;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $monthStart = now()->startOfMonth();

        $shipments = DB::table('shipments')
            ->whereNull('deleted_at')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when created_at >= ? then 1 else 0 end) as this_month', [$monthStart])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as delivered', [ShipmentStatus::Delivered->value])
            ->first();

        // اشتراكات تنتهي خلال أسبوعين — تذكير تجديد لا تقرير
        $expiring = Subscription::acrossCompanies()
            ->whereIn('status', ['trialing', 'active'])
            ->whereDate('ends_at', '<=', now()->addDays(14))
            ->with(['plan:id,name', 'company:id,name,slug,status'])
            ->orderBy('ends_at')
            ->get();

        $mrr = (int) Subscription::acrossCompanies()
            ->whereIn('status', ['active'])
            ->get()
            ->sum(fn (Subscription $s) => $s->billing_cycle === 'yearly'
                ? (int) round($s->price / 12)
                : $s->price);

        return view('platform.dashboard', [
            'companies' => [
                'total'     => Company::count(),
                'active'    => Company::where('status', 'active')->count(),
                'trial'     => Company::where('status', 'trial')->count(),
                'suspended' => Company::where('status', 'suspended')->count(),
            ],
            'shipments' => $shipments,
            'mrr'       => $mrr,
            'expiring'  => $expiring,
            'audit'     => AuditLog::latest('id')->limit(15)->get(),
        ]);
    }
}
