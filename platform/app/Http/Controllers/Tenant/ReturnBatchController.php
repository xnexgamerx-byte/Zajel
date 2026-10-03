<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Returns\HandOverReturns;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\ReturnBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * دفعات الراجع وإيصالاتها، و«تسليم الراجع لمندوب الاستلام» كما في المعتاد.
 *
 * مندوب الاستلام يمرّ بتجّاره كل يوم: يأخذ رواجعهم معه بدل أن ينتظروها في
 * المخزن. يُسلَّم له ما على الرفّ لتجّاره، بإيصالٍ لكل تاجر يُطبع ويوقَّع
 * عند الباب — ويبقى «لم يؤكَّد» حتى يؤكّده التاجر من بوابته أو موظّف.
 */
class ReturnBatchController extends Controller
{
    public function __construct(protected HandOverReturns $handover) {}

    public function pickup(Request $request): View
    {
        $courierId = $request->integer('courier_id') ?: null;
        $couriers = Courier::picking()->active()->visibleTo($request->user())->orderBy('name')->get(['id', 'name', 'code']);

        return view('tenant.returns.pickup', [
            'couriers'   => $couriers,
            'courier'    => $courierId ? $couriers->firstWhere('id', $courierId) : null,
            'shipments'  => $courierId ? $this->handover->ready(pickupCourierId: $courierId, viewer: $request->user()) : collect(),
            'perCourier' => $this->handover->ready(viewer: $request->user())->filter(fn ($s) => $s->merchant?->pickup_courier_id)
                ->countBy(fn ($s) => $s->merchant->pickup_courier_id),
        ]);
    }

    public function handToPickup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'courier_id'     => ['required', 'integer'],
            'shipment_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'shipment_ids.*' => ['integer'],
            'note'           => ['nullable', 'string', 'max:255'],
        ], [], ['shipment_ids' => 'الشحنات', 'courier_id' => 'مندوب الاستلام']);

        $courier = Courier::picking()->active()->visibleTo($request->user())->find($data['courier_id']);

        if (! $courier) {
            return back()->withErrors(['courier_id' => 'مندوب الاستلام غير موجود أو غير مفعّل.']);
        }

        $batches = $this->handover->toPickupCourier($data['shipment_ids'], $courier, $request->user(), $data['note'] ?? null);

        if ($batches->isEmpty()) {
            return back()->withErrors(['shipment_ids' => 'لم تُسلَّم أي شحنة — قد تكون مُسلَّمة سلفاً.']);
        }

        return back()
            ->with('success', 'سُلّم لمندوب الاستلام '.$courier->name.' '
                .\App\Support\Arabic::shipments((int) $batches->sum('shipments_count'))
                .'، بإيصالاتٍ عددها '.$batches->count().': لكلّ تاجرٍ إيصاله.')
            ->with('print', route('return-batches.print-many', ['ids' => $batches->pluck('id')->all()]));
    }

    /** «دفعات الراجع»: كل تسليمٍ بإيصاله، ومن أين خرج، وهل استُلم فعلاً. */
    public function index(Request $request): View
    {
        $batches = ReturnBatch::query()
            ->visibleTo($request->user())
            ->with(['merchant:id,business_name,code', 'courier:id,name', 'handedBy:id,name'])
            ->when($request->integer('merchant_id'), fn ($q, $id) => $q->where('merchant_id', $id))
            ->when($request->integer('courier_id'), fn ($q, $id) => $q->where('courier_id', $id))
            ->when(in_array($request->query('via'), array_keys(ReturnBatch::VIA), true), fn ($q) => $q->where('via', $request->query('via')))
            ->when($request->query('received') === 'no', fn ($q) => $q->whereNull('received_at'))
            ->when($request->query('received') === 'yes', fn ($q) => $q->whereNotNull('received_at'))
            ->when($request->date('from'), fn ($q, $d) => $q->whereFromDate('handed_at', $d->toDateString()))
            ->when($request->date('to'), fn ($q, $d) => $q->whereUntilDate('handed_at', $d->toDateString()))
            ->latest('id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.returns.batches', [
            'batches'   => $batches,
            'merchants' => Merchant::visibleTo($request->user())->orderBy('business_name')->get(['id', 'business_name']),
            'couriers'  => Courier::picking()->visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
            'waiting'   => ReturnBatch::visibleTo($request->user())->whereNull('received_at')->count(),
        ]);
    }

    public function confirm(Request $request, ReturnBatch $batch): RedirectResponse
    {
        return $this->handover->confirmReceived($batch, $request->user()->name, $request->user())
            ? back()->with('success', "أُكّد استلام التاجر لإيصال {$batch->number}.")
            : back()->withErrors(['batch' => "إيصال {$batch->number} مؤكَّدٌ سلفاً."]);
    }

    public function print(ReturnBatch $batch): View
    {
        return $this->printView(ReturnBatch::whereKey($batch->id)->get());
    }

    /** إيصالات تسليمٍ واحد لمندوب الاستلام: لكل تاجرٍ صفحته */
    public function printMany(Request $request): View
    {
        $ids = array_map('intval', array_slice((array) $request->query('ids', []), 0, 100));

        return $this->printView(ReturnBatch::whereIn('id', $ids)->visibleTo($request->user())->orderBy('id')->get());
    }

    private function printView($batches): View
    {
        abort_if($batches->isEmpty(), 404);

        $batches->load([
            'merchant:id,business_name,code,phone,address,city_id', 'merchant.city:id,name_ar',
            'courier:id,name,phone', 'handedBy:id,name',
            'shipments' => fn ($q) => $q->with(['governorate:id,name_ar', 'lastFailureReason:id,name_ar'])->orderBy('id'),
        ]);

        return view('tenant.returns.receipt', ['batches' => $batches]);
    }
}
