<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CourierRequest;
use App\Models\Branch;
use App\Models\Courier;
use App\Models\CourierZone;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Username;
use App\Services\SequenceGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CourierController extends Controller
{
    public function index(Request $request): View
    {
        $couriers = Courier::query()
            ->visibleTo($request->user())
            ->with(['branch:id,name', 'parent:id,name', 'zones' => fn ($q) => $q->whereNull('city_id')->with('governorate:id,name_ar')])
            ->when($request->query('q'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('phone', $term)->orWhere('code', $term)
            ))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            // «مندوب التوصيل الأب»: هو وفريقه
            ->when($request->integer('parent_id'), fn ($q, $id) => $q->where(fn ($w) => $w->where('parent_id', $id)->orWhere('id', $id)))
            ->withCount([
                'deliveries as open_count' => fn ($q) => $q->where('status', ShipmentStatus::OutForDelivery->value),
                'subs',
            ])
            ->orderBy('name')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.couriers.index', [
            'couriers' => $couriers,
            'parents'  => Courier::whereHas('subs')->visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): View
    {
        return view('tenant.couriers.form', $this->formData() + ['courier' => new Courier, 'zones' => []]);
    }

    public function store(CourierRequest $request, SequenceGenerator $sequences): RedirectResponse
    {
        $courier = DB::transaction(function () use ($request, $sequences) {
            $data = $request->validated();

            $courier = Courier::create(collect($data)->except(['zones', 'create_login', 'username', 'password'])->all() + [
                'code' => $sequences->next('courier'),
            ]);

            $this->syncZones($courier, $data['zones'] ?? []);

            if ($request->boolean('create_login')) {
                $this->createLogin($courier, $data['password'] ?? null, $data['username'] ?? null);
            }

            return $courier;
        });

        return redirect()
            ->route('couriers.show', $courier)
            ->with('success', "أُضيف المندوب {$courier->name} برمز {$courier->code}.");
    }

    public function show(Courier $courier): View
    {
        $courier->load(['branch', 'user', 'parent:id,name,code', 'zones.governorate:id,name_ar', 'zones.city:id,name_ar',
            'subs' => fn ($q) => $q->withCount(['deliveries as open_count' => fn ($d) => $d->where('status', ShipmentStatus::OutForDelivery->value)])
                ->orderBy('name')]);

        return view('tenant.couriers.show', [
            'courier'      => $courier,
            'payouts'      => $courier->picks() ? $courier->payouts()->with('paidBy:id,name')->latest('id')->limit(10)->get() : collect(),
            // العدد من قاعدة البيانات، والقائمة أحدث خمسين — وما زاد في قائمة الشحنات
            'openCount'    => Shipment::where('delivery_courier_id', $courier->id)
                ->where('status', ShipmentStatus::OutForDelivery->value)->count(),
            'open'         => Shipment::where('delivery_courier_id', $courier->id)
                ->where('status', ShipmentStatus::OutForDelivery->value)
                ->with('governorate:id,name_ar')
                ->latest('id')->limit(50)->get(),
            'transactions' => Transaction::forAccount('courier', $courier->id)
                ->latest('id')->limit(30)->get(),
        ]);
    }

    public function edit(Courier $courier): View
    {
        return view('tenant.couriers.form', $this->formData() + [
            'courier' => $courier,
            // المحافظات كلّها وحدها: المنطقة داخل محافظةٍ تُسند من «مناطق المندوبين»
            'zones'   => $courier->zones()->whereNull('city_id')->pluck('governorate_id')->all(),
        ]);
    }

    public function update(CourierRequest $request, Courier $courier): RedirectResponse
    {
        $data = $request->validated();

        $courier->update(collect($data)->except(['zones', 'create_login', 'username', 'password'])->all());
        $this->syncZones($courier, $data['zones'] ?? []);

        return redirect()
            ->route('couriers.show', $courier)
            ->with('success', 'حُفظت بيانات المندوب.');
    }

    /**
     * مناطق التغطية على مستوى المحافظة — تكفي للتوزيع اليومي. ولا تمسّ ما
     * أُسند من «مناطق المندوبين» لمنطقةٍ بعينها: تعديل هاتف المندوب لا يمحو مناطقه.
     */
    protected function syncZones(Courier $courier, array $governorateIds): void
    {
        CourierZone::where('courier_id', $courier->id)->whereNull('city_id')->delete();

        foreach (array_unique($governorateIds) as $governorateId) {
            CourierZone::create([
                'courier_id'     => $courier->id,
                'governorate_id' => $governorateId,
            ]);
        }
    }

    /** يدخل باسمٍ مختار، وإلّا برقم هاتفه (User::booted). */
    protected function createLogin(Courier $courier, ?string $password, ?string $username = null): void
    {
        $username = Username::normalise($username) ?? Username::canonical($courier->phone);

        // مجموعةً واحدة: «أو» لا تفلت من نطاق الشركة الذي يُضاف إلى الاستعلام
        if (User::where(fn ($q) => $q->where('phone', $courier->phone)->orWhere('username', $username))->exists()) {
            return;
        }

        $user = User::create([
            'name'       => $courier->name,
            'username'   => $username,
            'phone'      => $courier->phone,
            'password'   => $password,
            'role'       => UserRole::Courier,
            'courier_id' => $courier->id,
            'branch_id'  => $courier->branch_id,
            'is_active'  => true,
        ]);

        $courier->update(['user_id' => $user->id]);
    }

    protected function formData(): array
    {
        $user = auth()->user();

        return [
            // موظّف الفرع يضيف لفرعه وحده
            'branches'     => Branch::where('is_active', true)
                ->when($user->isBranchLimited(), fn ($q) => $q->whereKey($user->branch_id))
                ->orderBy('name')->get(['id', 'name']),
            'governorates' => Governorate::where('is_active', true)->orderedForCompany()->get(['id', 'name_ar']),
            // الأب مندوب توصيلٍ ليس فرعيّاً
            'parents'      => Courier::delivering()->whereNull('parent_id')->visibleTo($user)->orderBy('name')->get(['id', 'name', 'code']),
        ];
    }
}
