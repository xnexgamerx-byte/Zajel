<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\MerchantRequest;
use App\Models\Branch;
use App\Models\City;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\PriceList;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SequenceGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MerchantController extends Controller
{
    public function index(Request $request): View
    {
        $merchants = Merchant::query()
            ->with(['governorate:id,name_ar', 'branch:id,name'])
            ->when($request->query('q'), function ($q, $term) {
                $q->where(fn ($w) => $w->where('business_name', 'like', "%{$term}%")
                    ->orWhere('owner_name', 'like', "%{$term}%")
                    ->orWhere('phone', $term)
                    ->orWhere('code', $term));
            })
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->withCount('shipments')
            ->orderBy('business_name')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.merchants.index', [
            'merchants' => $merchants,
            'owed'      => (int) DB::table('merchants')
                ->where('company_id', $request->user()->company_id)
                ->whereNull('deleted_at')
                ->where('balance', '>', 0)
                ->sum('balance'),
        ]);
    }

    public function create(): View
    {
        return view('tenant.merchants.form', $this->formData() + ['merchant' => new Merchant]);
    }

    public function store(MerchantRequest $request, SequenceGenerator $sequences): RedirectResponse
    {
        $merchant = DB::transaction(function () use ($request, $sequences) {
            $data = $request->validated();

            // create_login و password ليسا عمودين — يُستبعدان قبل الحفظ
            $merchant = Merchant::create(
                collect($data)->except(['create_login', 'password'])->all() + [
                    'code'               => $sequences->next('merchant'),
                    'created_by_user_id' => $request->user()->id,
                ]
            );

            if ($request->boolean('create_login')) {
                $this->createLogin($merchant, $request->validated('password'));
            }

            return $merchant;
        });

        return redirect()
            ->route('merchants.show', $merchant)
            ->with('success', "أُضيف التاجر {$merchant->business_name} برمز {$merchant->code}.");
    }

    public function show(Merchant $merchant): View
    {
        $merchant->load(['governorate', 'city', 'branch', 'priceList']);

        $byStatus = Shipment::query()
            ->where('merchant_id', $merchant->id)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->toBase()
            ->get();

        return view('tenant.merchants.show', [
            'merchant'     => $merchant,
            'byStatus'     => $byStatus,
            'statuses'     => ShipmentStatus::cases(),
            'transactions' => Transaction::forAccount('merchant', $merchant->id)
                ->latest('id')->limit(30)->get(),
            'recent'       => Shipment::where('merchant_id', $merchant->id)
                ->latest('id')->limit(10)->get(),
        ]);
    }

    public function edit(Merchant $merchant): View
    {
        return view('tenant.merchants.form', $this->formData() + ['merchant' => $merchant]);
    }

    public function update(MerchantRequest $request, Merchant $merchant): RedirectResponse
    {
        $merchant->update(
            collect($request->validated())->except(['create_login', 'password'])->all()
        );

        return redirect()
            ->route('merchants.show', $merchant)
            ->with('success', 'حُفظت بيانات التاجر.');
    }

    /** حساب دخول للتاجر على تطبيقه — بالهاتف نفسه المسجَّل. */
    protected function createLogin(Merchant $merchant, ?string $password): void
    {
        if (User::where('phone', $merchant->phone)->exists()) {
            return;
        }

        User::create([
            'name'        => $merchant->owner_name ?: $merchant->business_name,
            'phone'       => $merchant->phone,
            'email'       => $merchant->email,
            'password'    => $password,
            'role'        => UserRole::Merchant,
            'merchant_id' => $merchant->id,
            'branch_id'   => $merchant->branch_id,
            'is_active'   => true,
        ]);
    }

    protected function formData(): array
    {
        return [
            'governorates' => Governorate::where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar']),
            'cities'       => City::where('is_active', true)->orderBy('name_ar')->get(['id', 'governorate_id', 'name_ar']),
            'branches'     => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'priceLists'   => PriceList::where('is_active', true)->orderBy('name')->get(['id', 'name', 'is_default']),
        ];
    }
}
