<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Transport\BagShipments;
use App\Http\Controllers\Controller;
use App\Models\Bag;
use App\Models\Hub;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * الأكياس: تكييس وفتح.
 *
 * الشاشة مبنيّة حول الماسح الضوئي لا حول الفأرة — حقل واحد يقبل رقم
 * الوصل ويُفرَّغ فوراً، فيمسح الموظّف مئة طرد بلا أن يرفع يده.
 */
class BagController extends Controller
{
    public function __construct(protected BagShipments $bags) {}

    public function index(Request $request): View
    {
        $bags = Bag::with(['fromHub:id,name', 'toHub:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when(! $request->query('status'), fn ($q) => $q->whereIn('status', ['open', 'sealed', 'in_transit', 'received']))
            ->when($request->integer('to_hub_id'), fn ($q, $id) => $q->where('to_hub_id', $id))
            ->orderByRaw("case status when 'open' then 0 when 'received' then 1 when 'sealed' then 2 else 3 end")
            ->orderByDesc('id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        $hubs = Hub::where('is_active', true)->orderBy('name')->get(['id', 'name', 'branch_id']);
        $home = $this->homeHub($hubs, $request->user()->branch_id);

        return view('tenant.bags.index', [
            'bags'  => $bags,
            'hubs'  => $hubs,
            'home'  => $home,
            'away'  => $hubs->firstWhere('id', '!=', $home?->id),
            'counts' => Bag::toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_hub_id' => ['required', 'integer'],
            'to_hub_id'   => ['required', 'integer', 'different:from_hub_id'],
            'notes'       => ['nullable', 'string', 'max:500'],
        ], [], ['from_hub_id' => 'مركز الانطلاق', 'to_hub_id' => 'مركز الوصول']);

        $from = Hub::find($data['from_hub_id']);
        $to = Hub::find($data['to_hub_id']);

        if (! $from || ! $to) {
            return back()->withErrors(['to_hub_id' => 'اختر مركزين موجودين.']);
        }

        $bag = $this->bags->create($from, $to, $request->user(), $data['notes'] ?? null);

        return redirect()->route('bags.show', $bag)->with('success', "أُنشئ الكيس {$bag->code}. ابدأ بمسح الوصولات.");
    }

    public function show(Bag $bag): View
    {
        return view('tenant.bags.show', [
            'bag'       => $bag->load(['fromHub:id,name', 'toHub:id,name']),
            'shipments' => $bag->liveShipments()
                ->with(['merchant:id,business_name', 'governorate:id,name_ar'])
                ->orderByPivot('added_at', 'desc')
                ->get(),
            'manifest'  => $bag->manifests()->with('toHub:id,name')->latest('manifests.id')->first(),
        ]);
    }

    /** المسح: سطر لكل رقم، فيعمل الماسح الضوئي والّلصق معاً. */
    public function add(Request $request, Bag $bag): RedirectResponse
    {
        $data = $request->validate([
            'numbers' => ['required', 'string', 'max:20000'],
        ], [], ['numbers' => 'أرقام الوصولات']);

        $numbers = preg_split('/[\s,،]+/u', $data['numbers'], -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $result = $this->bags->add($bag, $numbers, $request->user());

        // رفض بعض الأرقام ليس فشل حفظ: الباقي دخل الكيس فعلاً، ولافتة
        // «تعذّر الحفظ» فوق رسالة نجاح تناقضها تُربك الموظّف.
        return back()
            ->with('success', 'أُضيفت الوصولات، عددها '.$result['added']->count().'.')
            ->with('rejected', $result['errors']);
    }

    public function remove(Request $request, Bag $bag, Shipment $shipment): RedirectResponse
    {
        $this->bags->remove($bag, $shipment, $request->user());

        return back()->with('success', "أُخرجت {$shipment->number} من الكيس.");
    }

    public function seal(Request $request, Bag $bag): RedirectResponse
    {
        $this->bags->seal($bag, $request->user());

        return back()->with('success', "خُتم الكيس {$bag->code}. حمّله على كشف نقل.");
    }

    public function open(Request $request, Bag $bag): RedirectResponse
    {
        $this->bags->open($bag, $request->user());

        return back()->with(
            'success',
            "فُتح الكيس {$bag->code}. شحناته الآن في مخزن {$bag->toHub?->name}.",
        );
    }

    /**
     * المركز الذي يقف فيه المستخدم.
     *
     * الترتيب الأبجدي يجعل الافتراضي «البصرة» لموظّف في بغداد، فيُنشئ
     * كيساً بالمسار معكوساً ولا ينتبه إلّا بعد ختمه.
     */
    protected function homeHub($hubs, ?int $branchId)
    {
        return $hubs->firstWhere('branch_id', $branchId) ?? $hubs->first();
    }
}
