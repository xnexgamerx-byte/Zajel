<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ReceiveAtHub;
use App\Actions\Transport\ReceiveFromBranch;
use App\Actions\Transport\SendToBranch;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Hub;
use App\Models\Manifest;
use App\Models\Shipment;
use App\Support\Arabic;
use App\Support\ScanCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «النقل بين الفروع» في شاشةٍ واحدة (docs/plan/38).
 *
 * الواصل إليك: «استلمت الكل» يستلم الكشف ويفتح أكياسه — الشحنات تدخل مخزنك،
 * والراجع يصل راجعاً جاهزاً لتسليم تاجره.
 * والمُرسَل منك: تختار الفرع، فتظهر شحنات محافظته التي على رفّك ورواجع تجّاره،
 * وتختار من يحملها، فيُبنى الكيس والكشف ويُرسَلان معاً.
 *
 * والأكياس والكشوف بشاشاتها باقيةٌ لمن يريد تقسيم الحمولة بيده.
 */
class TransferController extends Controller
{
    public function __construct(
        protected SendToBranch $sender,
        protected ReceiveFromBranch $receiver,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $hubs = Hub::active()->with('branch:id,name,governorate_id')->orderBy('name')->get();
        $here = $this->here($request, $hubs);

        $destinations = $here ? $hubs->reject(fn (Hub $hub) => $hub->id === $here->id)->values() : collect();
        $counts = $here ? $this->counts($here, $destinations, $user) : collect();
        $to = $destinations->firstWhere('id', $request->integer('to'));

        /*
        | بلا ضغطة اختيار (docs/plan/42): الفرع الذي ينتظره أكثر يُفتح وحده، فتظهر شحناته ورواجعه
        | جاهزةً للإرسال. وفرعٌ بلا شيءٍ ينتظره يُختار باليد كما كان.
        */
        if (! $to && ! $request->has('to')) {
            // وفرعٌ بمخزنين يُفتح على مخزنه الأوّل (Hub::forBranch) لا على الثاني بالعدد نفسه
            $primary = $destinations->map(fn (Hub $hub) => Hub::forBranch($hub->branch_id)?->id)->filter()->unique();
            $busiest = $counts->map(fn (array $c) => $c['shipments'] + $c['returns'])->filter()
                ->sortByDesc(fn (int $n, int $hubId) => [$n, $primary->contains($hubId) ? 1 : 0])->keys()->first();
            $to = $busiest ? $destinations->firstWhere('id', $busiest) : null;
        }

        // الفروع بما ينتظرها أوّلاً، والمخزن الأوّل لكل فرعٍ قبل مخزنه الثاني
        $primaryHubs = $destinations->map(fn (Hub $hub) => Hub::forBranch($hub->branch_id)?->id)->filter()->unique();
        $destinations = $destinations->sortBy(fn (Hub $hub) => [
            -(($counts[$hub->id]['shipments'] ?? 0) + ($counts[$hub->id]['returns'] ?? 0)),
            $primaryHubs->contains($hub->id) ? 0 : 1,
            $hub->name,
        ])->values();

        // من حمل آخر كشفٍ من هنا يُقترح لهذا: السائق نفسه يأخذ خطّه كل يوم
        $lastTrip = $here ? Manifest::query()->where('from_hub_id', $here->id)
            ->when($to, fn (Builder $q) => $q->orderByRaw('to_hub_id = ? desc', [$to->id]))
            ->latest('id')->first(['id', 'courier_id', 'driver_name', 'driver_phone', 'vehicle_number']) : null;

        return view('tenant.transfers.index', [
            'lastTrip'     => $lastTrip,
            'hubs'         => $hubs,
            'here'         => $here,
            'canChooseHub' => ! $user->isBranchLimited(),
            'destinations' => $destinations,
            'to'           => $to,
            'counts'       => $counts,
            'shipments'    => $here && $to ? $this->candidates($here, $to, $user, returns: false) : collect(),
            'returns'      => $here && $to ? $this->candidates($here, $to, $user, returns: true) : collect(),
            // الواصل إليك: في الطريق، أو استُلم بالطريقة القديمة ولم تُفتح أكياسه
            'incoming'     => $here ? Manifest::visibleTo($user)
                ->with(['fromHub:id,name', 'courier:id,name'])
                ->where('to_hub_id', $here->id)
                ->where(fn (Builder $q) => $q->where('status', 'dispatched')
                    ->orWhere(fn (Builder $a) => $a->where('status', 'arrived')
                        ->whereHas('bags', fn (Builder $b) => $b->where('bags.status', 'received'))))
                ->orderBy('departed_at')
                ->get() : collect(),
            'outgoing'     => $here ? Manifest::visibleTo($user)
                ->with(['toHub:id,name', 'courier:id,name'])
                ->where('from_hub_id', $here->id)
                ->where('status', 'dispatched')
                ->orderByDesc('departed_at')
                ->get() : collect(),
            // طلبات لم تصل مع كشوفها (docs/plan/50): مسحُها في «استلام بالمسح» يُدخلها المخزن
            'missing'      => Shipment::visibleTo($user)->whereNotNull('missing_at')
                ->with(['merchant:id,business_name', 'governorate:id,name_ar'])
                ->orderByDesc('missing_at')->limit(200)->get(),
            'carriers'     => Courier::transferring()->where('status', 'active')->visibleTo($user)
                ->orderBy('name')->get(['id', 'name', 'phone', 'vehicle_number']),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'from_hub_id'    => ['nullable', 'integer'],
            'to_hub_id'      => ['required', 'integer'],
            'shipment_ids'   => ['nullable', 'array', 'max:1000'],
            'shipment_ids.*' => ['integer'],
            'numbers'        => ['nullable', 'string', 'max:30000'],
            // مندوب النقل بين الفروع (المناورة)، أو سائقٌ من خارج الشركة بالاسم
            'courier_id'     => ['nullable', 'integer', Rule::exists('couriers', 'id')
                                    ->where('company_id', $user->company_id)->where('type', 'transfer')],
            'driver_name'    => ['nullable', 'required_without:courier_id', 'string', 'max:160'],
            'driver_phone'   => ['nullable', 'string', 'regex:/^07[0-9]{9}$/'],
            'vehicle_number' => ['nullable', 'string', 'max:40'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ], [
            'driver_name.required_without' => 'اختر من يحمل الشحنات: مندوب نقل، أو اكتب اسم السائق.',
        ], ['to_hub_id' => 'الفرع', 'driver_phone' => 'هاتف السائق', 'courier_id' => 'مندوب النقل']);

        $hubs = Hub::active()->get();
        $from = $this->here($request, $hubs, $data['from_hub_id'] ?? null);
        $to = $hubs->firstWhere('id', (int) $data['to_hub_id']);

        if (! $from || ! $to) {
            return back()->withInput()->withErrors(['to_hub_id' => 'اختر الفرع المرسَل إليه.']);
        }

        [$ids, $unknown] = $this->resolve($data['shipment_ids'] ?? [], (string) ($data['numbers'] ?? ''), $user);

        if ($ids === []) {
            return back()->withInput()->withErrors(['shipment_ids' => $unknown
                ? 'لا وصل بهذه الأرقام: '.implode('، ', array_slice($unknown, 0, 5))
                : 'اختر الشحنات أو امسحها.']);
        }

        $result = $this->sender->handle($from, $to, $ids, $data, $user);
        $manifest = $result['manifest'];

        $message = "أُرسل إلى {$to->name} مع الكشف {$manifest->code}: ".Arabic::shipments($result['sent'])
            .($result['returns'] ? "، منها {$result['returns']} راجع لتجّار الفرع" : '').'.';

        $skipped = collect($result['skipped'])->map(fn ($why, $number) => "{$number} ({$why})")
            ->merge(collect($unknown)->map(fn ($number) => "{$number} (لا وصل بهذا الرقم)"));

        if ($skipped->isNotEmpty()) {
            $message .= ' ولم يُرسَل: '.$skipped->take(8)->implode('، ').($skipped->count() > 8 ? '…' : '');
        }

        return redirect()->route('transfers.index', array_filter(['from' => $request->user()->isBranchLimited() ? null : $from->id]))
            ->with('success', $message)
            ->with('print', route('manifests.print', $manifest));
    }

    /**
     * استلام الكشف الوارد بمسح كلّ طلبٍ بوحده (docs/plan/50): قائمة طلباته كلّها، والمسح
     * يعلّم ما وصل، وما لم يُمسح يُعرف بعينه «لم يصل» بدل أن يُستلم الكيس كلّه.
     */
    public function receiveForm(Request $request, Manifest $manifest): View
    {
        $this->assertDestination($request, $manifest);
        abort_unless(in_array($manifest->status, ['dispatched', 'arrived'], true), 404);

        return view('tenant.transfers.receive', [
            'manifest'  => $manifest->load(['fromHub:id,name', 'toHub:id,name', 'courier:id,name']),
            'shipments' => $this->manifestShipments($manifest),
        ]);
    }

    /** الوصل الممسوح: أهو من طلبات هذا الكشف؟ */
    public function lookup(Request $request, Manifest $manifest): JsonResponse
    {
        $this->assertDestination($request, $manifest);
        $scan = ScanCode::read((string) $request->query('code'));

        if (! $scan->usable()) {
            return response()->json(['error' => 'امسح الوصل أو اكتب رقمه.'], 422);
        }

        $shipment = $scan->find(Shipment::query()->with('merchant:id,business_name'));

        if (! $shipment) {
            return response()->json(['error' => $scan->notFound()], 404);
        }

        return response()->json([
            'id' => $shipment->id, 'number' => $shipment->number, 'merchant' => $shipment->merchant?->business_name,
        ]);
    }

    public function receive(Request $request, Manifest $manifest): RedirectResponse
    {
        $this->assertDestination($request, $manifest);

        $data = $request->validate([
            'shipment_ids'   => ['nullable', 'array', 'max:2000'],
            'shipment_ids.*' => ['integer'],
        ], [], ['shipment_ids' => 'الطلبات الممسوحة']);

        // ضغطةٌ بلا مسح لا تُعلن الكشف كلّه مفقوداً
        if (empty($data['shipment_ids'])) {
            return back()->withErrors(['shipment_ids' => 'لم تمسح أيّ طلب — امسح ما وصل ثم «استلم الممسوح».']);
        }

        // يُستلم ما مُسح وحده، وما سواه من طلبات الكشف «لم يصل» بعينه (docs/plan/50)
        $result = $this->receiver->handle($manifest, $request->user(), shipmentIds: $data['shipment_ids']);

        $message = "استُلم الكشف {$manifest->code}: ".Arabic::shipments($result['shipments']).' دخلت مخزنك';

        if ($result['returns']) {
            $message .= "، منها {$result['returns']} راجع وصل راجعاً — سلّمه لتاجره من «تسليم الراجع للتاجر»";
        }

        if ($result['missing']) {
            $message .= '. ولم تصل '.Arabic::shipments(count($result['missing'])).': '
                .collect($result['missing'])->take(8)->implode('، ').(count($result['missing']) > 8 ? '…' : '')
                .' — تظهر في «طلبات لم تصل»';
        }

        return redirect()->route('transfers.index')->with('success', $message.'.');
    }

    /** الاستلام لفرع الوصول: موظّف فرعٍ آخر يرى الكشف ولا يستلمه عنه */
    private function assertDestination(Request $request, Manifest $manifest): void
    {
        if ($request->user()->isBranchLimited()
            && (int) Hub::whereKey($manifest->to_hub_id)->value('branch_id') !== (int) $request->user()->branch_id) {
            abort(403);
        }
    }

    /**
     * طلبات الكشف في أكياسه، إلّا ما فُتح كيسه سلفاً.
     *
     * @return Collection<int, Shipment>
     */
    private function manifestShipments(Manifest $manifest): Collection
    {
        $bagIds = $manifest->bags()->whereIn('bags.status', ['sealed', 'in_transit', 'received'])->pluck('bags.id');

        return Shipment::query()
            ->whereIn('current_bag_id', $bagIds)
            ->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar'])
            ->orderBy('id')
            ->get();
    }

    /**
     * المركز الذي يُرسَل منه ويُستلم فيه: مركز فرع الموظّف. وصاحب الشركة
     * (أو الفرع الرئيسي بلا قيد) يختار المركز، وافتراضيّه مخزن الرئيسي.
     *
     * @param  Collection<int, Hub>  $hubs
     */
    protected function here(Request $request, Collection $hubs, ?int $chosen = null): ?Hub
    {
        $own = ReceiveAtHub::hubOf($request->user());

        if ($request->user()->isBranchLimited()) {
            return $own;
        }

        $chosen ??= $request->integer('from') ?: null;

        return ($chosen ? $hubs->firstWhere('id', $chosen) : null) ?? $own ?? $hubs->first();
    }

    /** محافظة الفرع الذي يُرسَل إليه: شحنات زبائنها تُسلَّم من هناك. */
    protected function governorateOf(Hub $hub): ?int
    {
        $id = $hub->governorate_id ?? $hub->branch?->governorate_id;

        return $id ? (int) $id : null;
    }

    /** شحناتٌ إلى محافظة الفرع، أو رواجع تجّاره — على رفّ $here. */
    protected function candidatesQuery(Hub $here, Hub $to, $user, bool $returns): Builder
    {
        $q = SendToBranch::sendable(Shipment::query()->visibleTo($user), $here);

        if ($returns) {
            // الراجع بمكانه المسجَّل وحده، كما في «فرز الراجع»: راجعٌ بلا مركز لا يُعرف أنه على رفّنا
            return $q->where('shipments.status', ShipmentStatus::Returning->value)
                ->where('shipments.hub_id', $here->id)
                ->whereIn('shipments.merchant_id', \App\Models\Merchant::withTrashed()->select('id')
                    ->where('branch_id', $to->branch_id ?? 0));
        }

        return $q->where('shipments.status', ShipmentStatus::AtHub->value)
            ->where('shipments.governorate_id', $this->governorateOf($to) ?? 0);
    }

    /** @return Collection<int, Shipment> */
    protected function candidates(Hub $here, Hub $to, $user, bool $returns): Collection
    {
        return $this->candidatesQuery($here, $to, $user, $returns)
            ->with(['merchant:id,business_name', 'city:id,name_ar', 'lastFailureReason:id,name_ar'])
            ->orderBy('shipments.id')
            ->limit(500)
            ->get();
    }

    /**
     * كم ينتظر كل فرع: يظهر بجانب اسمه قبل اختياره.
     *
     * @param  Collection<int, Hub>  $destinations
     * @return Collection<int, array{shipments: int, returns: int}>
     */
    protected function counts(Hub $here, Collection $destinations, $user): Collection
    {
        return $destinations->mapWithKeys(fn (Hub $to) => [$to->id => [
            'shipments' => $this->candidatesQuery($here, $to, $user, returns: false)->count(),
            'returns'   => $this->candidatesQuery($here, $to, $user, returns: true)->count(),
        ]]);
    }

    /**
     * المختار بمربّعاته، والممسوح بأرقامه (سطرٌ لكل وصل، أو رابط رمز QR).
     *
     * @param  array<int>  $ids
     * @return array{0: list<int>, 1: list<string>}
     */
    protected function resolve(array $ids, string $numbers, $user): array
    {
        $scans = collect(preg_split('/[\s,،]+/u', $numbers) ?: [])
            ->map(fn ($raw) => ScanCode::read((string) $raw))
            ->filter(fn (ScanCode $scan) => $scan->code !== '')
            ->unique(fn (ScanCode $scan) => $scan->code.'|'.$scan->token);

        $unknown = [];

        if ($scans->isNotEmpty()) {
            $codes = $scans->filter->usable()->pluck('code')->unique()->values()->all();
            $found = Shipment::query()->visibleTo($user)
                ->where(fn (Builder $q) => $q->whereIn('number', $codes ?: [''])->orWhereIn('barcode', $codes ?: ['']))
                ->get(['id', 'company_id', 'number', 'barcode']);

            foreach ($scans as $scan) {
                $shipment = $found->first(fn (Shipment $s) => $scan->matches($s));

                if ($shipment) {
                    $ids[] = $shipment->id;
                } else {
                    $unknown[] = $scan->code;
                }
            }
        }

        return [array_values(array_unique(array_map('intval', $ids))), $unknown];
    }
}
