<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Accounts\ChangeLogin;
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
use App\Support\Username;
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
            ->visibleTo($request->user())
            ->with(['governorate:id,name_ar', 'branch:id,name'])
            ->when($request->query('q'), function ($q, $term) {
                $q->where(fn ($w) => $w->where('business_name', 'like', "%{$term}%")
                    ->orWhere('owner_name', 'like', "%{$term}%")
                    ->orWhere('phone', $term)
                    ->orWhere('code', $term));
            })
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('goods_type'), fn ($q, $t) => $q->where('goods_type', $t))
            ->when($request->query('vip') === '1', fn ($q) => $q->where('is_vip', true))
            ->when($request->integer('pickup_courier_id'), fn ($q, $id) => $q->where('pickup_courier_id', $id))
            ->when($request->integer('sales_user_id'), fn ($q, $id) => $q->where('sales_user_id', $id))
            ->when($request->query('portal') === '0', fn ($q) => $q->where('portal_access', false))
            ->with(['pickupCourier:id,name'])
            ->withCount('shipments')
            ->orderBy('business_name')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.merchants.index', [
            'merchants' => $merchants,
            'pickupCouriers' => \App\Models\Courier::picking()->visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
            'salesUsers' => User::where('is_sales', true)->visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
            'owed'      => (int) Merchant::query()->visibleTo($request->user())->where('balance', '>', 0)->sum('balance'),
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

            // create_login واسم المستخدم وكلمة المرور ليست أعمدةً للتاجر — تُستبعد قبل الحفظ
            $merchant = Merchant::create(
                collect($data)->except(['create_login', 'username', 'password'])->all() + [
                    'code'               => $sequences->next('merchant'),
                    'created_by_user_id' => $request->user()->id,
                ]
            );

            if ($request->boolean('create_login')) {
                $this->createLogin($merchant, $request->validated('password'), $request->validated('username'));
            }

            return $merchant;
        });

        return redirect()
            ->route('merchants.show', $merchant)
            ->with('success', "أُضيف التاجر {$merchant->business_name} برمز {$merchant->code}.");
    }

    public function show(Merchant $merchant): View
    {
        $merchant->load(['governorate', 'city', 'branch', 'priceList', 'pickupCourier:id,name', 'salesUser:id,name']);

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
        return view('tenant.merchants.form', $this->formData() + [
            'merchant' => $merchant,
            'account'  => $merchant->loginAccount(),
        ]);
    }

    public function update(MerchantRequest $request, Merchant $merchant, ChangeLogin $login): RedirectResponse
    {
        $data = $request->validated();

        $merchant->update(collect($data)->except(['create_login', 'username', 'password'])->all());

        // حساب دخوله: يُغيَّر اسمه أو كلمة مروره، أو يُنشأ له إن لم يكن له حساب
        if ($account = $merchant->loginAccount()) {
            $login->handle($account, $data['username'] ?? null, $data['password'] ?? null, $request->user());
        } elseif (! empty($data['create_login'])) {
            $this->createLogin($merchant, $data['password'] ?? null, $data['username'] ?? null);
        }

        return redirect()
            ->route('merchants.show', $merchant)
            ->with('success', 'حُفظت بيانات التاجر.');
    }

    /** حساب دخول للتاجر على تطبيقه — باسمٍ مختار، وإلّا بهاتفه المسجَّل. */
    protected function createLogin(Merchant $merchant, ?string $password, ?string $username = null): void
    {
        $username = Username::normalise($username) ?? Username::canonical($merchant->phone);

        // مجموعةً واحدة: «أو» لا تفلت من نطاق الشركة الذي يُضاف إلى الاستعلام
        if (User::where(fn ($q) => $q->where('phone', $merchant->phone)->orWhere('username', $username))->exists()) {
            return;
        }

        User::create([
            'name'        => $merchant->owner_name ?: $merchant->business_name,
            'username'    => $username,
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
        $user = auth()->user();

        return [
            'governorates' => Governorate::where('is_active', true)->orderedForCompany()->get(['id', 'name_ar']),
            'cities'       => City::where('is_active', true)->orderBy('name_ar')->get(['id', 'governorate_id', 'name_ar']),
            // موظّف الفرع يضيف لفرعه وحده
            'branches'     => Branch::where('is_active', true)
                ->when($user->isBranchLimited(), fn ($q) => $q->whereKey($user->branch_id))
                ->orderBy('name')->get(['id', 'name']),
            'priceLists'   => PriceList::where('is_active', true)->orderBy('name')->get(['id', 'name', 'is_default']),
            'pickupCouriers' => \App\Models\Courier::picking()->active()->visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'salesUsers'   => User::where('is_sales', true)->where('is_active', true)->visibleTo($user)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
