<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierSettlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CourierSettlementController extends Controller
{
    public function index(Request $request): View
    {
        return view('tenant.settlements.couriers.index', [
            'settlements' => CourierSettlement::with('courier:id,name,code')
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('courier_id'), fn ($q, $c) => $q->where('courier_id', $c))
                ->latest('id')
                ->paginate(config('zajel.per_page'))
                ->withQueryString(),

            // من عنده نقد أو عمولة معلّقة هو من يحتاج كشفاً
            'pending' => Courier::query()
                ->where(fn ($q) => $q->where('cash_in_hand', '!=', 0)->orWhere('commission_balance', '!=', 0))
                ->orderByDesc('cash_in_hand')
                ->get(),
        ]);
    }

    public function store(Request $request, BuildCourierSettlement $build): RedirectResponse
    {
        $data = $request->validate([
            'courier_id' => ['required', 'integer'],
            'from'       => ['nullable', 'date'],
            'to'         => ['nullable', 'date', 'after_or_equal:from'],
        ], [], ['courier_id' => 'المندوب', 'from' => 'من تاريخ', 'to' => 'إلى تاريخ']);

        $courier = Courier::find($data['courier_id']);

        if (! $courier) {
            return back()->withErrors(['courier_id' => 'المندوب غير موجود.']);
        }

        $settlement = $build->handle($courier, $request->user(), [
            'from' => $data['from'] ?? null,
            'to'   => $data['to'] ?? null,
        ]);

        return redirect()
            ->route('settlements.couriers.show', $settlement)
            ->with('success', "فُتح كشف {$settlement->code}، فيه ".\App\Support\Arabic::shipments((int) $settlement->shipments_count).'.');
    }

    public function show(CourierSettlement $settlement): View
    {
        $settlement->load(['courier', 'lines.shipment.governorate:id,name_ar']);

        return view('tenant.settlements.couriers.show', compact('settlement'));
    }

    public function confirm(Request $request, CourierSettlement $settlement, ConfirmCourierSettlement $confirm): RedirectResponse
    {
        $data = $request->validate([
            'deductions' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ], [], ['deductions' => 'الخصومات']);

        $confirm->handle(
            $settlement,
            $request->user(),
            (int) ($data['deductions'] ?? 0),
            $data['notes'] ?? null,
        );

        return back()->with('success', "أُقفِل كشف {$settlement->code} واستُلم النقد.");
    }
}
