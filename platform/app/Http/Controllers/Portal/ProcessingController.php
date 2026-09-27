<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Shipments\ProcessFailedAttempt;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «للمعالجة» في بوابة التاجر المسموح له («إدخال طلبات العميل للمعالجة»): هو
 * أعرف بزبونه — يتّصل به ويقرّر إعادة التوصيل أو التأجيل أو الإرجاع، فلا تنتظر
 * شحنته دورها عند موظّف المتابعة. والقرار نفسه يمرّ بطريق الموظّف ويُكتب باسمه.
 */
class ProcessingController extends Controller
{
    public function index(Request $request): View
    {
        $merchant = $request->attributes->get('merchant');
        abort_unless($merchant->can_process, 403);

        return view('portal.processing.index', [
            'shipments' => Shipment::where('merchant_id', $merchant->id)
                ->where('status', ShipmentStatus::FailedAttempt->value)
                ->with(['governorate:id,name_ar', 'city:id,name_ar', 'lastFailureReason:id,name_ar'])
                ->orderBy('status_changed_at')
                ->paginate(config('zajel.per_page'))
                ->withQueryString(),
            'actions'   => ProcessFailedAttempt::ACTIONS,
        ]);
    }

    public function store(Request $request, Shipment $shipment, ProcessFailedAttempt $process): RedirectResponse
    {
        $merchant = $request->attributes->get('merchant');
        abort_unless($merchant->can_process, 403);
        abort_unless((int) $shipment->merchant_id === (int) $merchant->id, 404);

        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', array_keys(ProcessFailedAttempt::ACTIONS))],
            'until'  => ['nullable', 'required_if:action,postpone', 'date', 'after_or_equal:today', 'before:+60 days'],
            'note'   => ['nullable', 'string', 'max:255'],
        ], [
            'until.required_if'    => 'التأجيل إلى متى؟ اختر اليوم الذي اتّفقت عليه مع زبونك.',
            'until.after_or_equal' => 'موعد التأجيل اليوم أو بعده.',
        ], ['until' => 'موعد التأجيل', 'note' => 'ما قاله الزبون']);

        $process->handle($shipment, $data['action'], $request->user(), 'merchant', $data['until'] ?? null,
            $data['note'] ?? null, $request->ip());

        return back()->with('success', "سُجّل قرارك في {$shipment->number}: ".ProcessFailedAttempt::ACTIONS[$data['action']]
            .($data['action'] === 'postpone' ? ' إلى '.$data['until'] : '').'.');
    }
}
