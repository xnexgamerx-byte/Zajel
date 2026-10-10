<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Actions\Shipments\ProcessFailedAttempt;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «للمعالجة» في تطبيق التاجر (docs/plan/54): شحناته التي لم تُسلَّم، وقراره فيها — إعادة توصيل،
 * أو تأجيلٌ إلى موعد، أو إرجاع — بطريق البوابة نفسه (ProcessFailedAttempt) ويُكتب باسمه.
 * وتاجرٌ لم تُفعّل له الشركة المعالجة يراها ولا يقرّر: يعالجها موظّفوها.
 */
class ProcessingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');

        $page = Shipment::where('merchant_id', $merchant->id)
            ->where('status', ShipmentStatus::FailedAttempt->value)
            ->with(['governorate:id,name_ar', 'city:id,name_ar', 'lastFailureReason:id,name_ar'])
            ->orderBy('status_changed_at')
            ->paginate(20);

        return response()->json([
            'allowed' => (bool) $merchant->can_process,
            'actions' => ProcessFailedAttempt::ACTIONS,
            'total'   => $page->total(),
            'page'    => $page->currentPage(),
            'last'    => $page->lastPage(),
            'data'    => $page->getCollection()->map(fn (Shipment $s) => ShipmentController::row($s) + [
                'reason'   => $s->lastFailureReason?->name_ar,
                'attempts' => (int) $s->attempts_count,
                'waiting'  => (int) $s->status_changed_at?->diffInHours(now()),
                // زبونه هو: رقمه كاملاً ليتّصل به من التطبيق
                'phone'    => $s->recipient_phone,
            ])->values(),
        ]);
    }

    public function store(Request $request, Shipment $shipment, ProcessFailedAttempt $process): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        abort_unless((int) $shipment->merchant_id === (int) $merchant->id, 404);

        if (! $merchant->can_process) {
            return response()->json(['message' => 'المعالجة من التطبيق غير مفعّلة لحسابك — تعالجها الشركة وتخبرك.'], 403);
        }

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

        return response()->json([
            'message' => "سُجّل قرارك في {$shipment->number}: ".ProcessFailedAttempt::ACTIONS[$data['action']]
                .($data['action'] === 'postpone' ? ' إلى '.$data['until'] : '').'.',
        ]);
    }
}
