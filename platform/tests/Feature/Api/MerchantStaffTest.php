<?php

namespace Tests\Feature\Api;

use App\Actions\Shipments\CreateShipment;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Bag;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFeature;
use App\Models\Governorate;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/plan/87: «بالمخزن» ومكان الراجع، وخانات «رحلة شحناتك اليوم»، و«تقويم الشحنات»، وموظّفو المتجر بصلاحياتهم.
 */
class MerchantStaffTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->staff = $this->makeUser($this->company);
        $this->owner = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'أحمد', 'phone' => '07790000001', 'password' => 'password', 'role' => UserRole::Merchant,
            'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));
    }

    private function api(string $path): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/api/v1'.$path;
    }

    /** رمز حسابٍ لطلبٍ واحد: الحارس لا يحفظ الحساب السابق بين الطلبات */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function tokenFor(string $username, string $password = 'password'): string
    {
        return $this->postJson($this->api('/login'), ['username' => $username, 'password' => $password, 'app' => 'merchant'])
            ->assertOk()->json('token');
    }

    private function shipment(ShipmentStatus $status = ShipmentStatus::Created, array $extra = []): Shipment
    {
        $shipment = Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id,
            'recipient_name' => 'زبون',
            'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id,
            'city_id' => $this->area('المنصور'),
            'address' => 'بغداد',
            'cod_amount' => 45_000,
        ], $this->owner));

        Tenancy::runFor($this->company, fn () => $shipment->forceFill(
            ['status' => $status->value, 'status_changed_at' => now()] + $extra,
        )->save());

        return $shipment->refresh();
    }

    /** موظّفٌ يضيفه صاحب المتجر من التطبيق */
    private function addStaff(string $ownerToken, array $permissions, string $phone = '07701112233'): array
    {
        return $this->as($ownerToken)->postJson($this->api('/merchant/staff'), [
            'name' => 'سجى', 'phone' => $phone, 'password' => 'secret12', 'permissions' => $permissions,
        ])->assertCreated()->json('staff');
    }

    public function test_the_warehouse_chip_and_where_each_return_is(): void
    {
        [$home, $away, $bag] = Tenancy::runFor($this->company, function () {
            $home = Hub::create(['code' => 'H1', 'name' => 'مركز بغداد', 'type' => 'main', 'branch_id' => $this->merchant->branch_id, 'is_active' => true]);
            $babil = Governorate::query()->whereKeyNot($this->baghdad()->id)->firstOrFail();
            $branch = Branch::create(['code' => 'B2', 'name' => 'فرع '.$babil->name_ar, 'governorate_id' => $babil->id]);
            $away = Hub::create(['code' => 'H2', 'name' => 'مركز '.$babil->name_ar, 'type' => 'branch', 'branch_id' => $branch->id,
                'governorate_id' => $babil->id, 'is_active' => true]);
            $bag = Bag::create(['code' => 'BG1', 'from_hub_id' => $away->id, 'to_hub_id' => $home->id, 'status' => 'in_transit']);

            return [$home, $away, $bag];
        });
        $babil = $away->governorate->name_ar;

        $atHub = $this->shipment(ShipmentStatus::AtHub, ['hub_id' => $home->id]);
        $this->shipment(ShipmentStatus::OutForDelivery);
        $withCourier = $this->shipment(ShipmentStatus::Returning, ['return_confirmed_at' => now()]);
        $atHome = $this->shipment(ShipmentStatus::Returning, ['return_received_at' => now(), 'hub_id' => $home->id]);
        $atAway = $this->shipment(ShipmentStatus::Returning, ['return_received_at' => now(), 'hub_id' => $away->id]);
        $inBag = $this->shipment(ShipmentStatus::Returning, ['return_received_at' => now(), 'hub_id' => $away->id, 'current_bag_id' => $bag->id]);
        // ما سُلِّم إليه في كشوفه وحدها
        $this->shipment(ShipmentStatus::Returned, ['return_received_at' => now()]);

        $token = $this->tokenFor($this->owner->username);

        // «بالمخزن»: ما في مخزننا وحده، شريحةً بعددها بعد «قيد التوصيل»
        $list = $this->as($token)->getJson($this->api('/merchant/shipments?filter=warehouse'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $atHub->number);
        $chips = collect($list->json('filters'));
        $this->assertSame(['all', 'new', 'open', 'warehouse'], $chips->pluck('key')->take(4)->all());
        $this->assertSame(1, $chips->firstWhere('key', 'warehouse')['count']);
        $this->assertSame('بالمخزن', $chips->firstWhere('key', 'warehouse')['label']);

        // تحت كلّ راجعٍ مكانه
        $places = collect($this->as($token)->getJson($this->api('/merchant/shipments?filter=attention'))->assertOk()->json('data'))
            ->pluck('return_place', 'number');
        $this->assertSame('راجع عند المندوب', $places[$withCourier->number]);
        $this->assertSame('راجع في المخزن', $places[$atHome->number]);
        $this->assertSame('راجع عند مركز محافظة '.$babil, $places[$atAway->number]);
        $this->assertSame('راجع في الطريق إلى فرعك', $places[$inBag->number]);
        $this->assertCount(4, $places);

        $this->as($token)->getJson($this->api('/merchant/shipments/'.$atAway->id))->assertOk()
            ->assertJsonPath('return_place', 'راجع عند مركز محافظة '.$babil);
        $this->as($token)->getJson($this->api('/merchant/shipments/'.$atHub->id))->assertOk()
            ->assertJsonPath('return_place', null);

        // خانات «رحلة شحناتك اليوم» بأعدادها، وكلٌّ يفتح قائمته
        $states = collect($this->as($token)->getJson($this->api('/merchant/states'))->assertOk()
            ->assertJsonPath('total', 6)->json('data'))->pluck('count', 'key');
        $this->assertSame(1, $states['warehouse']);
        $this->assertSame(1, $states['courier']);
        $this->assertSame(1, $states['return_courier']);
        $this->assertSame(3, $states['return_hub']);
        $this->assertSame(1, $states['returns']);
        $this->assertSame(0, $states['created']);
        $this->as($token)->getJson($this->api('/merchant/shipments?filter=return_hub'))->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_the_shipments_calendar_counts_each_day_by_where_it_ended(): void
    {
        $this->shipment(ShipmentStatus::Delivered);
        $this->shipment(ShipmentStatus::Delivered);
        $this->shipment(ShipmentStatus::Delivered);
        $this->shipment(ShipmentStatus::Returning);
        $this->shipment(ShipmentStatus::Postponed);
        $old = $this->shipment(ShipmentStatus::Returned);
        Tenancy::runFor($this->company, fn () => $old->forceFill(['created_at' => now()->subDays(20)])->save());

        $token = $this->tokenFor($this->owner->username);

        $week = $this->as($token)->getJson($this->api('/merchant/calendar'))->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonPath('rate', 75)
            ->assertJsonCount(7, 'days')
            ->assertJsonPath('days.6.date', now('Asia/Baghdad')->toDateString())
            ->assertJsonPath('days.6.delivered', 3)
            ->assertJsonPath('days.6.postponed', 1)
            ->assertJsonPath('by_user', []);
        $this->assertSame(['delivered' => 3, 'open' => 0, 'postponed' => 1, 'processing' => 0, 'returned' => 1],
            collect($week->json('buckets'))->pluck('count', 'key')->all());

        // فترةٌ يختارها، وأطولها ثلاثة أشهر
        $this->as($token)->getJson($this->api('/merchant/calendar?from='.now()->subDays(25)->toDateString().'&to='.now()->subDays(15)->toDateString()))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('rate', 0)->assertJsonCount(11, 'days');
        $this->as($token)->getJson($this->api('/merchant/calendar?from='.now()->subYear()->toDateString()))
            ->assertOk()->assertJsonCount(93, 'days');
        $this->as($token)->getJson($this->api('/merchant/calendar?from=yesterday'))->assertUnprocessable();
    }

    public function test_merchant_staff_is_off_until_the_platform_opens_it(): void
    {
        $token = $this->tokenFor($this->owner->username);

        $this->as($token)->getJson($this->api('/merchant/home'))->assertOk()
            ->assertJsonPath('tools.staff', false)
            ->assertJsonPath('permissions', ['shipments', 'create', 'print', 'pickups', 'processing', 'finance', 'support', 'owner']);
        $this->as($token)->getJson($this->api('/merchant/staff'))->assertForbidden();
    }

    public function test_an_entry_clerk_sees_and_does_only_what_was_opened_to_him(): void
    {
        $this->setFeature($this->company, Feature::MerchantStaff);
        $ownerToken = $this->tokenFor($this->owner->username);
        $shipment = $this->shipment(ShipmentStatus::OutForDelivery);

        $this->as($ownerToken)->getJson($this->api('/merchant/staff'))->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('roles.0.key', 'entry')
            ->assertJsonCount(7, 'permissions');
        $clerk = $this->addStaff($ownerToken, ['create', 'print', 'pickups', 'shipments']);
        $this->assertSame('entry', $clerk['role']);
        $this->assertSame('07701112233', $clerk['username']);

        $login = $this->postJson($this->api('/login'), ['username' => '07701112233', 'password' => 'secret12', 'app' => 'merchant'])
            ->assertOk()
            ->assertJsonPath('user.name', 'سجى')
            ->assertJsonPath('merchant.permissions', ['shipments', 'create', 'print', 'pickups']);
        $token = $login->json('token');

        // الرئيسية بلا رصيدٍ ولا معالجة، وأرقام الشحنات له
        $this->as($token)->getJson($this->api('/merchant/home'))->assertOk()
            ->assertJsonPath('name', 'سجى')
            ->assertJsonPath('balance', null)
            ->assertJsonPath('processing.allowed', false)
            ->assertJsonPath('tools.staff', false)
            ->assertJsonPath('stats.in_delivery', 1);

        // ما فُتح له يعمل
        $this->as($token)->getJson($this->api('/merchant/shipments'))->assertOk();
        $this->as($token)->getJson($this->api('/merchant/shipments/form'))->assertOk();
        $this->as($token)->getJson($this->api('/merchant/labels'))->assertOk();
        $this->as($token)->getJson($this->api('/merchant/calendar'))->assertOk()->assertJsonPath('by_user', [
            ['name' => 'أحمد', 'count' => 1],
        ]);
        // والشحنة بمبلغها، بلا أجورٍ ولا مستحقّ
        $this->as($token)->getJson($this->api('/merchant/shipments/'.$shipment->id))->assertOk()
            ->assertJsonPath('money.cod', 45000)
            ->assertJsonPath('money.hidden', true)
            ->assertJsonMissingPath('money.due');

        // وما لم يُفتح يُرفض
        foreach (['/merchant/finance', '/merchant/requests', '/merchant/processing', '/merchant/support', '/merchant/staff'] as $path) {
            $this->as($token)->getJson($this->api($path))->assertForbidden();
        }
        $this->as($token)->deleteJson($this->api('/merchant/logo'))->assertForbidden();
    }

    public function test_the_owner_changes_suspends_and_removes_a_staff_member(): void
    {
        $this->setFeature($this->company, Feature::MerchantStaff);
        $ownerToken = $this->tokenFor($this->owner->username);
        $accountant = $this->addStaff($ownerToken, ['finance', 'shipments']);
        $this->assertSame('accountant', $accountant['role']);
        $token = $this->tokenFor('07701112233', 'secret12');

        $this->as($token)->getJson($this->api('/merchant/finance'))->assertOk();
        $this->as($token)->getJson($this->api('/merchant/home'))->assertOk()->assertJsonPath('printer', null);
        $this->as($token)->getJson($this->api('/merchant/shipments/form'))->assertForbidden();

        // صلاحيةٌ مخصّصة: من طلبه التالي
        $this->as($ownerToken)->putJson($this->api('/merchant/staff/'.$accountant['id']), [
            'name' => 'سجى', 'phone' => '07701112233', 'permissions' => ['print'],
        ])->assertOk()->assertJsonPath('staff.role', 'custom')->assertJsonPath('staff.permissions', ['print']);
        $this->as($token)->getJson($this->api('/merchant/finance'))->assertForbidden();
        $this->as($token)->getJson($this->api('/merchant/home'))->assertOk()
            ->assertJsonPath('stats', null)->assertJsonPath('recent', [])->assertJsonPath('attention.items', []);

        // موقوف: يخرج من هاتفه ولا يدخل
        $this->as($ownerToken)->putJson($this->api('/merchant/staff/'.$accountant['id']), [
            'name' => 'سجى', 'phone' => '07701112233', 'permissions' => ['print'], 'active' => false,
        ])->assertOk()->assertJsonPath('staff.active', false);
        $this->as($token)->getJson($this->api('/merchant/home'))->assertUnauthorized();
        $this->postJson($this->api('/login'), ['username' => '07701112233', 'password' => 'secret12', 'app' => 'merchant'])
            ->assertUnprocessable();

        // محذوف: هاتفه حرٌّ لموظّفٍ جديد
        $this->as($ownerToken)->deleteJson($this->api('/merchant/staff/'.$accountant['id']))->assertOk();
        $this->as($ownerToken)->getJson($this->api('/merchant/staff'))->assertOk()->assertJsonPath('data', []);
        $this->addStaff($ownerToken, ['shipments']);

        // صاحب المتجر يبقى حساب المتجر في الموقع
        $this->assertSame($this->owner->id, Tenancy::runFor($this->company, fn () => $this->merchant->loginAccount()?->id));
    }

    public function test_staff_validation_and_boundaries(): void
    {
        $this->setFeature($this->company, Feature::MerchantStaff);
        $ownerToken = $this->tokenFor($this->owner->username);

        $this->as($ownerToken)->postJson($this->api('/merchant/staff'), [
            'name' => 'سجى', 'phone' => '0770', 'password' => 'x', 'permissions' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['phone', 'password', 'permissions']);
        // «owner» لا تُعطى لموظّف، وهاتف حساب صاحب المتجر لا يُؤخذ
        $this->as($ownerToken)->postJson($this->api('/merchant/staff'), [
            'name' => 'سجى', 'phone' => '07701112233', 'password' => 'secret12', 'permissions' => ['owner'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['permissions.0']);
        $this->as($ownerToken)->postJson($this->api('/merchant/staff'), [
            'name' => 'سجى', 'phone' => '07790000001', 'password' => 'secret12', 'permissions' => ['shipments'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['phone']);

        // هاتف حساب صاحب المتجر، أو موظّفٌ لمتجرٍ آخر: لا يُعدَّل من هنا
        $other = $this->makeMerchant($this->company, 'M0002');
        $theirs = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'غيره', 'phone' => '07790000009', 'password' => 'password', 'role' => UserRole::Merchant,
            'merchant_id' => $other->id, 'is_active' => true, 'merchant_permissions' => ['shipments'],
        ]));
        $this->as($ownerToken)->putJson($this->api('/merchant/staff/'.$theirs->id), [
            'name' => 'x', 'phone' => '07790000009', 'permissions' => ['finance'],
        ])->assertNotFound();
        $this->as($ownerToken)->putJson($this->api('/merchant/staff/'.$this->owner->id), [
            'name' => 'x', 'phone' => '07790000001', 'permissions' => ['finance'],
        ])->assertNotFound();

        // الموظّف لا يدخل بوابة الموقع
        $clerk = $this->addStaff($ownerToken, ['shipments']);
        $user = Tenancy::runFor($this->company, fn () => User::find($clerk['id']));
        $this->actingAs($user)->get('http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/portal')->assertForbidden();

        // الميزة تُغلق: الموظّف لا يعمل، وصاحب المتجر كما كان
        $token = $this->tokenFor('07701112233', 'secret12');
        Tenancy::runFor($this->company, fn () => CompanyFeature::where('feature', Feature::MerchantStaff->value)->update(['enabled' => false]));
        $this->company->forgetFeatures();
        $this->as($token)->getJson($this->api('/merchant/home'))->assertForbidden();
        $this->postJson($this->api('/login'), ['username' => '07701112233', 'password' => 'secret12', 'app' => 'merchant'])
            ->assertUnprocessable();
        $this->as($ownerToken)->getJson($this->api('/merchant/home'))->assertOk();
    }
}
