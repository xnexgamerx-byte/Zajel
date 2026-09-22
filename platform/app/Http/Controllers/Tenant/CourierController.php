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
            ->with('branch:id,name')
            ->when($request->query('q'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('phone', $term)->orWhere('code', $term)
            ))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->withCount([
                'deliveries as open_count' => fn ($q) => $q->where('status', ShipmentStatus::OutForDelivery->value),
            ])
            ->orderBy('name')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.couriers.index', compact('couriers'));
    }

    public function create(): View
    {
        return view('tenant.couriers.form', $this->formData() + ['courier' => new Courier, 'zones' => []]);
    }

    public function store(CourierRequest $request, SequenceGenerator $sequences): RedirectResponse
    {
        $courier = DB::transaction(function () use ($request, $sequences) {
            $data = $request->validated();

            $courier = Courier::create(collect($data)->except(['zones', 'create_login', 'password'])->all() + [
                'code' => $sequences->next('courier'),
            ]);

            $this->syncZones($courier, $data['zones'] ?? []);

            if ($request->boolean('create_login')) {
                $this->createLogin($courier, $data['password'] ?? null);
            }

            return $courier;
        });

        return redirect()
            ->route('couriers.show', $courier)
            ->with('success', "أُضيف المندوب {$courier->name} برمز {$courier->code}.");
    }

    public function show(Courier $courier): View
    {
        $courier->load(['branch', 'user', 'zones.governorate:id,name_ar', 'zones.city:id,name_ar']);

        return view('tenant.couriers.show', [
            'courier'      => $courier,
            'open'         => Shipment::where('delivery_courier_id', $courier->id)
                ->where('status', ShipmentStatus::OutForDelivery->value)
                ->with('governorate:id,name_ar')
                ->latest('id')->get(),
            'transactions' => Transaction::forAccount('courier', $courier->id)
                ->latest('id')->limit(30)->get(),
        ]);
    }

    public function edit(Courier $courier): View
    {
        return view('tenant.couriers.form', $this->formData() + [
            'courier' => $courier,
            'zones'   => $courier->zones()->pluck('governorate_id')->all(),
        ]);
    }

    public function update(CourierRequest $request, Courier $courier): RedirectResponse
    {
        $data = $request->validated();

        $courier->update(collect($data)->except(['zones', 'create_login', 'password'])->all());
        $this->syncZones($courier, $data['zones'] ?? []);

        return redirect()
            ->route('couriers.show', $courier)
            ->with('success', 'حُفظت بيانات المندوب.');
    }

    /** مناطق التغطية على مستوى المحافظة — تكفي للتوزيع اليومي. */
    protected function syncZones(Courier $courier, array $governorateIds): void
    {
        CourierZone::where('courier_id', $courier->id)->delete();

        foreach (array_unique($governorateIds) as $governorateId) {
            CourierZone::create([
                'courier_id'     => $courier->id,
                'governorate_id' => $governorateId,
            ]);
        }
    }

    protected function createLogin(Courier $courier, ?string $password): void
    {
        if (User::where('phone', $courier->phone)->exists()) {
            return;
        }

        $user = User::create([
            'name'       => $courier->name,
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
        return [
            'branches'     => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'governorates' => Governorate::where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar']),
        ];
    }
}
