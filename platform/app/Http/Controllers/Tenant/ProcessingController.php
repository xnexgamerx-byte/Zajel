<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ProcessFailedAttempt;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Shipments\ShipmentFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «شحنات للمعالجة» كما في المعتاد: المحاولة الفاشلة لا تعود إلى المندوب
 * تلقائياً — يتّصل موظّف المتابعة بالزبون ويقرّر: إعادة توصيل، أو تأجيلٌ إلى
 * موعدٍ اتُّفق عليه، أو إرجاعٌ للتاجر. والقرار يُسجَّل بمن اتّخذه وبعد كم
 * انتظرت الشحنة، ومنه تُقرأ «موظّفو المتابعة» و«أداء المراجعة».
 *
 * وكل موظّفة كول سنتر تعالج شحنات محافظات اختصاصها وحدها (docs/plan/30): معالجة
 * بغداد لا تصل موظّفة البصرة، ولا تُعالَج منها برقمها.
 */
class ProcessingController extends Controller
{
    public const ACTIONS = ProcessFailedAttempt::ACTIONS;

    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'done' ? 'done' : 'pending';

        $pending = Shipment::query()
            ->visibleTo($request->user())
            ->inGovernoratesOf($request->user())
            ->where('shipments.status', ShipmentStatus::FailedAttempt->value);

        // «الزبون اتّصل بخصوص الوصل كذا»: البحث برقم الوصل أو هاتفه، ومناديب
        // الواحد بعينه — والشارة تعدّ كل ما ينتظر لا ما وافق البحث
        $found = ShipmentFilters::apply(clone $pending, $request);

        $mine = $request->user()->handledGovernorateIds();

        return view('tenant.processing.index', [
            'tab'       => $tab,
            'governorates' => $mine === [] ? collect()
                : Governorate::query()->whereIn('id', $mine)->orderedForCompany()->pluck('name_ar'),
            'pendingCount' => (clone $pending)->count(),
            'filtered'  => $request->filled('q') || $request->filled('courier_id'),
            'couriers'  => $tab === 'pending' ? Courier::delivering()->visibleTo($request->user())->orderBy('name')->get(['id', 'name']) : collect(),
            'shipments' => $tab === 'pending'
                ? $found->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar',
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
                    ->whereIn('shipment_id', Shipment::query()->visibleTo($request->user())
                        ->inGovernoratesOf($request->user())->select('shipments.id'))
                    ->with('shipment:id,number,status,merchant_id', 'shipment.merchant:id,business_name')
                    ->latest('id')
                    ->paginate(config('zajel.per_page'))
                    ->withQueryString()
                : null,
        ]);
    }

    public function store(Request $request, Shipment $shipment, ProcessFailedAttempt $process): RedirectResponse
    {
        // معالجة محافظةٍ أخرى لموظّفتها: لا تُفتح برقم الشحنة
        abort_unless($shipment->inGovernoratesOf($request->user()), 404);

        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', array_keys(self::ACTIONS))],
            'until'  => ['nullable', 'required_if:action,postpone', 'date', 'after_or_equal:today', 'before:+60 days'],
            'note'   => ['nullable', 'string', 'max:255'],
        ], [
            'until.required_if' => 'التأجيل إلى متى؟ اختر اليوم الذي اتُّفق عليه مع الزبون.',
            'until.after_or_equal' => 'موعد التأجيل اليوم أو بعده.',
        ], ['until' => 'موعد التأجيل', 'note' => 'ما قاله الزبون']);

        $process->handle($shipment, $data['action'], $request->user(), 'staff', $data['until'] ?? null,
            $data['note'] ?? null, $request->ip());

        return back()->with('success', "عولجت {$shipment->number}: ".self::ACTIONS[$data['action']]
            .($data['action'] === 'postpone' ? ' إلى '.$data['until'] : '').'.');
    }
}
