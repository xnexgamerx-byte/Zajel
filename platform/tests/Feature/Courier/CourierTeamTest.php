<?php

namespace Tests\Feature\Courier;

use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Dashboard\HomeAlerts;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «مندوب التوصيل الأب» و«المندوبون الفرعيّون» كما في المعتاد: الشحنة باسم
 * الفرعيّ وفي تطبيقه، والأب يرى فريقه ويُسوّى معه كشوفهم دفعةً واحدة.
 */
class CourierTeamTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $staff;

    private Courier $parent;

    private Courier $sub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->staff = $this->makeUser($this->company);

        [$this->parent, $this->sub] = Tenancy::runFor($this->company, function () {
            $parent = Courier::create(['code' => 'C1', 'name' => 'حسن الأب', 'phone' => '07720000001', 'type' => 'delivery',
                'status' => 'active', 'commission_per_delivery' => 1000]);
            $sub = Courier::create(['code' => 'C2', 'name' => 'علي الفرعي', 'phone' => '07720000002', 'type' => 'delivery',
                'status' => 'active', 'commission_per_delivery' => 1000, 'parent_id' => $parent->id]);

            return [$parent, $sub];
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'مندوب', 'phone' => '07720000099', 'type' => 'delivery', 'vehicle_type' => 'motorcycle', 'status' => 'active',
        ];
    }

    private function outFor(Courier $courier, array $then = []): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($courier, $then) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'زبون '.$courier->code, 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->staff);

            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ...$then] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->staff,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier->id : null]);
            }

            return $shipment->refresh();
        });
    }

    public function test_one_level_only_and_never_under_oneself(): void
    {
        // تحت فرعيٍّ: لا
        $this->actingAs($this->staff)->post($this->host().'/couriers', $this->payload(['parent_id' => $this->sub->id]))
            ->assertSessionHasErrors('parent_id');

        // من له فريقٌ لا يصير فرعيّاً، ولا يكون أباً لنفسه
        $other = Tenancy::runFor($this->company, fn () => Courier::create(['code' => 'C3', 'name' => 'أب آخر', 'phone' => '07720000003',
            'type' => 'delivery', 'status' => 'active']));
        $this->actingAs($this->staff)->put($this->host()."/couriers/{$this->parent->id}", $this->payload([
            'name' => $this->parent->name, 'phone' => $this->parent->phone, 'parent_id' => $other->id,
        ]))->assertSessionHasErrors('parent_id');
        $this->actingAs($this->staff)->put($this->host()."/couriers/{$other->id}", $this->payload([
            'name' => $other->name, 'phone' => $other->phone, 'parent_id' => $other->id,
        ]))->assertSessionHasErrors('parent_id');

        // مندوب استلامٍ لا يكون أباً لمندوب توصيل
        $pickup = Tenancy::runFor($this->company, fn () => Courier::create(['code' => 'P1', 'name' => 'مستلم', 'phone' => '07720000004',
            'type' => 'pickup', 'status' => 'active']));
        $this->actingAs($this->staff)->post($this->host().'/couriers', $this->payload(['parent_id' => $pickup->id]))
            ->assertSessionHasErrors('parent_id');

        // والصحيح يُحفظ
        $this->actingAs($this->staff)->post($this->host().'/couriers', $this->payload(['parent_id' => $this->parent->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Tenancy::runFor($this->company, fn () => $this->parent->subs()->count()));
    }

    public function test_the_list_filters_a_parent_with_his_team_and_says_who_is_under_whom(): void
    {
        Tenancy::runFor($this->company, fn () => Courier::create(['code' => 'C9', 'name' => 'مندوب مستقلّ', 'phone' => '07720000009',
            'type' => 'delivery', 'status' => 'active']));

        $this->actingAs($this->staff)->get($this->host().'/couriers?parent_id='.$this->parent->id)
            ->assertOk()->assertSee('حسن الأب')->assertSee('علي الفرعي')->assertSee('فرعيّ تحت حسن الأب')
            ->assertSee('له مندوبٌ فرعيّ')->assertDontSee('مندوب مستقلّ');

        $this->actingAs($this->staff)->get($this->host().'/couriers/'.$this->parent->id)
            ->assertOk()->assertSee('المندوبون الفرعيّون')->assertSee('علي الفرعي');
    }

    public function test_choosing_the_parent_shows_his_teams_shipments(): void
    {
        $subs = $this->outFor($this->sub);
        $own = $this->outFor($this->parent);

        $this->actingAs($this->staff)->get($this->host().'/shipments?courier_id='.$this->parent->id)
            ->assertSee($subs->number)->assertSee($own->number);
        $this->actingAs($this->staff)->get($this->host().'/shipments?courier_id='.$this->sub->id)
            ->assertSee($subs->number)->assertDontSee('>'.$own->number.'<', false);

        $this->actingAs($this->staff)->get($this->host().'/shipments/'.$subs->id)->assertSee('فرعيّ تحت حسن الأب');
    }

    public function test_the_parent_sees_his_team_in_his_app_and_the_sub_does_not(): void
    {
        $this->outFor($this->sub);

        [$parentUser, $subUser] = Tenancy::runFor($this->company, fn () => [
            User::create(['name' => 'حسن', 'phone' => '07720000001', 'password' => 'password', 'role' => UserRole::Courier,
                'courier_id' => $this->parent->id, 'is_active' => true]),
            User::create(['name' => 'علي', 'phone' => '07720000002', 'password' => 'password', 'role' => UserRole::Courier,
                'courier_id' => $this->sub->id, 'is_active' => true]),
        ]);

        $this->actingAs($parentUser)->get($this->host().'/courier')
            ->assertOk()->assertSee('فريقي')->assertSee('علي الفرعي')->assertDontSee('زبون C2');
        $this->actingAs($subUser)->get($this->host().'/courier')
            ->assertOk()->assertDontSee('فريقي')->assertSee('زبون C2');
    }

    public function test_the_parent_is_settled_with_his_team_in_one_step(): void
    {
        $this->outFor($this->sub, [ShipmentStatus::Delivered]);
        $this->outFor($this->parent, [ShipmentStatus::Delivered]);

        // الأب ظاهرٌ بزرّ فريقه
        $this->actingAs($this->staff)->get($this->host().'/settlements/couriers')->assertSee('كشوف فريقه');

        $this->actingAs($this->staff)->post($this->host().'/settlements/couriers/team', ['courier_id' => $this->parent->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'فُتحت كشوف فريق حسن الأب'));

        Tenancy::runFor($this->company, function () {
            $this->assertEqualsCanonicalizing([$this->parent->id, $this->sub->id], CourierSettlement::pluck('courier_id')->all());
        });

        // الكشف المفتوح لا يُكرَّر، ويُقال لمن
        $this->outFor($this->sub, [ShipmentStatus::Delivered]);
        $this->actingAs($this->staff)->post($this->host().'/settlements/couriers/team', ['courier_id' => $this->parent->id])
            ->assertSessionHas('success', 'لهؤلاء كشفٌ مفتوح سلفاً يُقفل أوّلاً: حسن الأب، علي الفرعي.');
        $this->assertSame(2, Tenancy::runFor($this->company, fn () => CourierSettlement::count()));

        // وفرعيٌّ لا فريق له
        $this->actingAs($this->staff)->post($this->host().'/settlements/couriers/team', ['courier_id' => $this->sub->id])
            ->assertSessionHasErrors('courier_id');
    }

    public function test_the_home_card_names_the_parent_and_the_sub(): void
    {
        $shipment = $this->outFor($this->sub);
        Tenancy::runFor($this->company, fn () => $shipment->forceFill(['status_changed_at' => now()->subDays(4)])->save());

        $alerts = collect(Tenancy::runFor($this->company, fn () => app(HomeAlerts::class)->for($this->staff)))->keyBy('key');

        $this->assertSame(['حسن الأب', 'علي الفرعي', 'شحنة واحدة'], $alerts['with_courier']['rows'][0]['cells']);
    }
}
