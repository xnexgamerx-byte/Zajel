<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\DuplicateDetector;
use App\Services\Reports\ReportPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * شاشات الرقابة.
 *
 * ليست تقارير تُقرأ بل قوائم تُحسَم: كل صفّ فيها يحتاج قراراً من موظّف،
 * وبقاؤه معلّقاً هو المشكلة نفسها.
 */
class ControlController extends Controller
{
    /** شحنات مشتبه بتكرارها — تُحاسَب مرّتين إن مرّت. */
    public function duplicates(DuplicateDetector $detector): View
    {
        return view('tenant.control.duplicates', [
            'suspects' => $detector->pending()->load('duplicateOf.merchant:id,business_name'),
            'window'   => DuplicateDetector::WINDOW_DAYS,
        ]);
    }

    /** ليست تكراراً: تُمسَح من القائمة بأثر مكتوب. */
    public function clearDuplicate(Request $request, Shipment $shipment): RedirectResponse
    {
        $shipment->forceFill(['duplicate_cleared_at' => now()])->save();

        return back()->with('success', "رُفع الاشتباه عن الوصل {$shipment->number}.");
    }

    /** تكرار فعليّ: تُلغى، ولا تُحذف. */
    public function cancelDuplicate(Request $request, Shipment $shipment, ChangeShipmentStatus $change): RedirectResponse
    {
        $original = $shipment->duplicateOf;

        $change->handle($shipment, ShipmentStatus::Cancelled, $request->user(), [
            'note' => 'ألغيت لأنها تكرار للوصل '.($original?->number ?? '—'),
        ]);

        return back()->with('success', "أُلغي الوصل المكرّر {$shipment->number}.");
    }

    /**
     * واصل إجباري — الرقابة على ما خرج عن المسار.
     *
     * الصفحة نفسها هي الرادع: من يعلم أن تسليمه القسريّ يظهر في قائمة
     * باسمه وسببه يفكّر مرّتين.
     */
    public function forced(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        return view('tenant.control.forced', [
            'period'    => $period,
            'shipments' => Shipment::query()
                ->where('is_forced', true)
                ->whereBetween('status_changed_at', [$from, $to])
                ->with(['merchant:id,business_name', 'deliveryCourier:id,name'])
                ->latest('status_changed_at')
                ->paginate(config('zajel.per_page'))
                ->withQueryString(),
            'byUser'    => Shipment::query()
                ->where('is_forced', true)
                ->whereBetween('status_changed_at', [$from, $to])
                ->join('users', 'users.id', '=', 'shipments.forced_by_user_id')
                ->selectRaw('users.name as name, count(*) as total, sum(shipments.cod_amount) as cod')
                ->groupBy('users.id', 'users.name')
                ->orderByDesc('total')
                ->toBase()
                ->get(),
        ]);
    }
}
