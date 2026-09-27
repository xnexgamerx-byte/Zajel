<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * «شحنات للمعالجة» كما في المعتاد: المحاولة الفاشلة لا تعود إلى المندوب
 * تلقائياً — يتّصل موظّف المتابعة بالزبون ويقرّر: إعادة توصيل، أو تأجيلٌ إلى
 * موعدٍ اتُّفق عليه، أو إرجاعٌ للتاجر. والقرار يُسجَّل بمن اتّخذه وبعد كم
 * انتظرت الشحنة، ومنه تُقرأ «موظّفو المتابعة» و«أداء المراجعة».
 */
class ProcessingController extends Controller
{
    public const ACTIONS = [
        'redeliver' => 'إعادة توصيل',
        'postpone'  => 'تأجيل',
        'return'    => 'إرجاع للتاجر',
    ];

    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'done' ? 'done' : 'pending';

        $pending = Shipment::query()
            ->visibleTo($request->user())
            ->where('shipments.status', ShipmentStatus::FailedAttempt->value);

        return view('tenant.processing.index', [
            'tab'       => $tab,
            'pendingCount' => (clone $pending)->count(),
            'shipments' => $tab === 'pending'
                ? $pending->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar',
                        'deliveryCourier:id,name', 'lastFailureReason:id,name_ar'])
                    ->orderBy('shipments.status_changed_at')
                    ->paginate(config('zajel.per_page'))
                    ->withQueryString()
                : null,
            // ما عولج في الأيام السبعة الأخيرة: القرار ومن اتّخذه وبعد كم
            'done'      => $tab === 'done'
                ? ShipmentEvent::query()
                    ->where('event_type', 'processed')
                    ->where('created_at', '>=', now()->subDays(7))
                    ->whereIn('shipment_id', Shipment::query()->visibleTo($request->user())->select('shipments.id'))
                    ->with('shipment:id,number,status,merchant_id', 'shipment.merchant:id,business_name')
                    ->latest('id')
                    ->paginate(config('zajel.per_page'))
                    ->withQueryString()
                : null,
        ]);
    }

    public function store(Request $request, Shipment $shipment, ChangeShipmentStatus $change): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', array_keys(self::ACTIONS))],
            'until'  => ['nullable', 'required_if:action,postpone', 'date', 'after_or_equal:today', 'before:+60 days'],
            'note'   => ['nullable', 'string', 'max:255'],
        ], [
            'until.required_if' => 'التأجيل إلى متى؟ اختر اليوم الذي اتُّفق عليه مع الزبون.',
            'until.after_or_equal' => 'موعد التأجيل اليوم أو بعده.',
        ], ['until' => 'موعد التأجيل', 'note' => 'ما قاله الزبون']);

        if ($shipment->status !== ShipmentStatus::FailedAttempt) {
            return back()->withErrors(['action' => "الشحنة {$shipment->number} «{$shipment->status->label()}»: عولجت سلفاً."]);
        }

        $waited = (int) $shipment->status_changed_at?->diffInMinutes(now());
        $note = filled($data['note'] ?? null) ? 'معالجة — '.$data['note'] : 'معالجة';

        DB::transaction(function () use ($shipment, $data, $change, $request, $note, $waited) {
            $options = ['note' => $note];

            [$to, $options] = match ($data['action']) {
                // مع مندوبها نفسه إن كان لها مندوب، وإلّا إلى المخزن تنتظر الإسناد
                'redeliver' => $shipment->delivery_courier_id
                    ? [ShipmentStatus::OutForDelivery, $options + ['courier_id' => $shipment->delivery_courier_id]]
                    : [ShipmentStatus::AtHub, $options],
                'postpone'  => [ShipmentStatus::Postponed, $options + ['scheduled_at' => \Illuminate\Support\Carbon::parse($data['until'])->startOfDay()]],
                'return'    => [ShipmentStatus::Returning, $options],
            };

            $change->handle($shipment, $to, $request->user(), $options);

            ShipmentEvent::create([
                'shipment_id' => $shipment->id,
                'from_status' => ShipmentStatus::FailedAttempt->value,
                'to_status'   => $to->value,
                'event_type'  => 'processed',
                'actor_type'  => 'user',
                'actor_id'    => $request->user()->id,
                'actor_name'  => $request->user()->name,
                'courier_id'  => $shipment->delivery_courier_id,
                'note'        => $note,
                'meta'        => ['action' => $data['action'], 'waited_minutes' => $waited, 'by' => 'staff',
                                  'said' => $data['note'] ?? null],
                'ip'          => $request->ip(),
            ]);
        });

        return back()->with('success', "عولجت {$shipment->number}: ".self::ACTIONS[$data['action']]
            .($data['action'] === 'postpone' ? ' إلى '.$data['until'] : '').'.');
    }
}
