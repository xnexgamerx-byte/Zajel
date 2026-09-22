<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Transport\RunManifest;
use App\Http\Controllers\Controller;
use App\Models\Bag;
use App\Models\Hub;
use App\Models\Manifest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * كشوف النقل بين المراكز، والوارد منها.
 *
 * الكشف ورقة مسؤولية: مَن حمّل، مَن قاد، مَن استلم. وقيمته تظهر يوم
 * يختفي كيس، فشاشة الاستلام لا تسأل «هل وصل الكشف؟» بل «أيّ الأكياس
 * وصل؟» — والباقي يُوسَم مفقوداً لا يُترَك بلا ذكر.
 */
class ManifestController extends Controller
{
    public function __construct(protected RunManifest $manifests) {}

    public function index(Request $request): View
    {
        $manifests = Manifest::with(['fromHub:id,name', 'toHub:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when(! $request->query('status'), fn ($q) => $q->whereIn('status', ['draft', 'dispatched', 'arrived']))
            ->orderByRaw("case status when 'draft' then 0 when 'dispatched' then 1 else 2 end")
            ->orderByDesc('id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        $hubs = Hub::where('is_active', true)->orderBy('name')->get(['id', 'name', 'branch_id']);
        $home = $this->homeHub($hubs, $request->user()->branch_id);

        return view('tenant.manifests.index', [
            'manifests' => $manifests,
            'hubs'      => $hubs,
            'home'      => $home,
            'away'      => $hubs->firstWhere('id', '!=', $home?->id),
            'inbound'   => Manifest::where('status', 'dispatched')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_hub_id'    => ['required', 'integer'],
            'to_hub_id'      => ['required', 'integer', 'different:from_hub_id'],
            'driver_name'    => ['nullable', 'string', 'max:160'],
            'driver_phone'   => ['nullable', 'string', 'max:20'],
            'vehicle_number' => ['nullable', 'string', 'max:40'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ], [], ['from_hub_id' => 'مركز الانطلاق', 'to_hub_id' => 'مركز الوصول']);

        $from = Hub::find($data['from_hub_id']);
        $to = Hub::find($data['to_hub_id']);

        if (! $from || ! $to) {
            return back()->withErrors(['to_hub_id' => 'اختر مركزين موجودين.']);
        }

        $manifest = $this->manifests->create($from, $to, $data, $request->user());

        return redirect()->route('manifests.show', $manifest)
            ->with('success', "أُنشئ الكشف {$manifest->code}. حمّل عليه الأكياس المختومة.");
    }

    public function show(Manifest $manifest): View
    {
        $manifest->load(['fromHub:id,name', 'toHub:id,name', 'bags.fromHub:id,name', 'bags.toHub:id,name']);

        return view('tenant.manifests.show', [
            'manifest'  => $manifest,
            // أكياس مختومة على المسار نفسه ولم تُحمَّل بعد على أي كشف
            'available' => $manifest->isDraft()
                ? Bag::where('status', 'sealed')
                    ->where('from_hub_id', $manifest->from_hub_id)
                    ->where('to_hub_id', $manifest->to_hub_id)
                    ->whereDoesntHave('manifests')
                    ->orderBy('id')
                    ->get()
                : collect(),
        ]);
    }

    public function load(Request $request, Manifest $manifest): RedirectResponse
    {
        $data = $request->validate(['bag_id' => ['required', 'integer']], [], ['bag_id' => 'الكيس']);

        $bag = Bag::find($data['bag_id']);

        if (! $bag) {
            return back()->withErrors(['bag_id' => 'الكيس غير موجود.']);
        }

        $this->manifests->load($manifest, $bag, $request->user());

        return back()->with('success', "حُمّل الكيس {$bag->code}.");
    }

    public function unload(Request $request, Manifest $manifest, Bag $bag): RedirectResponse
    {
        $this->manifests->unload($manifest, $bag);

        return back()->with('success', "أُنزل الكيس {$bag->code}.");
    }

    public function dispatchManifest(Request $request, Manifest $manifest): RedirectResponse
    {
        $this->manifests->dispatch($manifest, $request->user());

        return back()->with(
            'success',
            "انطلق الكشف {$manifest->code}. شحناته الآن قيد النقل.",
        );
    }

    /** ٢١ — استلام منفيست وارد. */
    public function inbound(): View
    {
        return view('tenant.manifests.inbound', [
            'manifests' => Manifest::with(['fromHub:id,name', 'toHub:id,name', 'bags'])
                ->where('status', 'dispatched')
                ->orderBy('departed_at')
                ->get(),
        ]);
    }

    public function receive(Request $request, Manifest $manifest): RedirectResponse
    {
        $data = $request->validate([
            'bag_ids'   => ['nullable', 'array'],
            'bag_ids.*' => ['integer'],
            'notes'     => ['nullable', 'string', 'max:500'],
        ], [], ['bag_ids' => 'الأكياس']);

        $this->manifests->receive($manifest, $data['bag_ids'] ?? [], $request->user(), $data['notes'] ?? null);

        $missing = $manifest->refresh()->missingBags();

        $message = "استُلم الكشف {$manifest->code}.";

        if ($missing) {
            $message .= " وسُجّلت أكياس مفقودة، عددها {$missing} — راجعها فوراً.";
        }

        return redirect()->route('manifests.show', $manifest)->with('success', $message);
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
