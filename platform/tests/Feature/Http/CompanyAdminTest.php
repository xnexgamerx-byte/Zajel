<?php

namespace Tests\Feature\Http;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\PriceList;
use App\Models\PriceListRule;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحة اليوم، والمستخدمون، والفروع، والتسعيرات — ما كانت الشركة
 * تعجز عن إدارته بنفسها.
 */
class CompanyAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company, UserRole::CompanyOwner);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    // ------------------------------------------------------ لوحة اليوم

    public function test_the_dashboard_counts_what_matters_this_morning(): void
    {
        $courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001',
            'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500,
            'cash_limit' => 10_000,
        ]));

        $shipment = Tenancy::runFor($this->company, function () use ($courier) {
            $s = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'زبون متعثّر',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->owner);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($s, ShipmentStatus::PickedUp, $this->owner);
            $change->handle($s->refresh(), ShipmentStatus::OutForDelivery, $this->owner,
                ['courier_id' => $courier->id]);
            $change->handle($s->refresh(), ShipmentStatus::FailedAttempt, $this->owner, [
                'failure_reason_id' => \App\Models\FailureReason::where('code', 'no_answer')->value('id'),
            ]);

            return $s->refresh();
        });

        $this->assertSame(ShipmentStatus::FailedAttempt, $shipment->status);

        $this->actingAs($this->owner)
            ->get($this->host().'/')
            ->assertOk()
            ->assertSee('لوحة اليوم')
            ->assertSee('زبون متعثّر')
            ->assertSee('لا يرد على الهاتف');
    }

    public function test_the_dashboard_is_not_for_merchants(): void
    {
        $merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));

        $this->actingAs($merchantUser)->get($this->host().'/')->assertForbidden();
    }

    // ------------------------------------------------------ المستخدمون

    public function test_a_company_adds_its_own_staff(): void
    {
        $this->actingAs($this->owner)
            ->post($this->host().'/users', [
                'name' => 'زينب العبيدي', 'phone' => '07733445566',
                'role' => 'customer_service', 'password' => 'secret123', 'is_active' => 1,
            ])
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () {
            $staff = User::where('phone', '07733445566')->firstOrFail();

            $this->assertSame(UserRole::CustomerService, $staff->role);
            $this->assertSame($this->company->id, $staff->company_id);
        });
    }

    public function test_a_duplicate_phone_inside_the_company_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post($this->host().'/users', [
                'name' => 'مكرّر', 'phone' => $this->owner->phone,
                'role' => 'operations', 'password' => 'secret123',
            ])
            ->assertSessionHasErrors('phone');
    }

    public function test_a_courier_role_cannot_be_created_from_the_users_screen(): void
    {
        $this->actingAs($this->owner)
            ->post($this->host().'/users', [
                'name' => 'مندوب', 'phone' => '07733440000',
                'role' => 'courier', 'password' => 'secret123',
            ])
            ->assertSessionHasErrors('role');
    }

    public function test_the_last_active_owner_cannot_be_demoted_or_suspended(): void
    {
        $this->actingAs($this->owner)
            ->put($this->host().'/users/'.$this->owner->id, [
                'name' => $this->owner->name, 'phone' => $this->owner->phone,
                'role' => 'operations', 'is_active' => 1,
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(UserRole::CompanyOwner, $this->owner->fresh()->role);

        $this->actingAs($this->owner)
            ->put($this->host().'/users/'.$this->owner->id, [
                'name' => $this->owner->name, 'phone' => $this->owner->phone,
                'role' => 'company_owner',
            ])
            ->assertSessionHasErrors('role');

        $this->assertTrue($this->owner->fresh()->is_active);
    }

    public function test_a_second_owner_makes_the_first_demotable(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/users', [
            'name' => 'شريك', 'phone' => '07733449999',
            'role' => 'company_owner', 'password' => 'secret123', 'is_active' => 1,
        ]);

        $this->actingAs($this->owner)
            ->put($this->host().'/users/'.$this->owner->id, [
                'name' => $this->owner->name, 'phone' => $this->owner->phone,
                'role' => 'company_admin', 'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(UserRole::CompanyAdmin, $this->owner->fresh()->role);
    }

    // --------------------------------------------------------- الفروع

    public function test_a_branch_is_added_and_only_one_stays_main(): void
    {
        $existing = Tenancy::runFor($this->company, fn () => Branch::where('is_main', true)->firstOrFail());

        $this->actingAs($this->owner)
            ->post($this->host().'/branches', [
                'code' => 'BSR', 'name' => 'فرع البصرة',
                'governorate_id' => Governorate::where('code', 'BSR')->value('id'),
                'is_main' => 1, 'is_active' => 1,
            ])
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($existing) {
            $this->assertSame(1, Branch::where('is_main', true)->count());
            $this->assertFalse($existing->fresh()->is_main);
            $this->assertTrue(Branch::where('code', 'BSR')->firstOrFail()->is_main);
        });
    }

    public function test_the_main_branch_cannot_simply_be_unset(): void
    {
        $main = Tenancy::runFor($this->company, fn () => Branch::where('is_main', true)->firstOrFail());

        $this->actingAs($this->owner)
            ->put($this->host().'/branches/'.$main->id, [
                'code' => $main->code, 'name' => $main->name, 'is_active' => 1,
            ])
            ->assertSessionHasErrors('is_main');

        $this->assertTrue($main->fresh()->is_main);
    }

    // ------------------------------------------------------ التسعيرات

    public function test_saving_the_matrix_creates_a_rule_per_filled_governorate(): void
    {
        $list = Tenancy::runFor($this->company, fn () => PriceList::where('is_default', true)->firstOrFail());
        $basra = Governorate::where('code', 'BSR')->firstOrFail();

        $this->actingAs($this->owner)
            ->put($this->host().'/pricing/'.$list->id, [
                'name' => 'التسعيرة الافتراضية',
                'weight_to_grams' => 5000,
                'is_default' => 1, 'is_active' => 1,
                'rows' => [
                    0 => ['delivery_fee' => 6000, 'return_fee' => 3000],
                    $this->baghdad()->id => ['delivery_fee' => 4000, 'return_fee' => 2000],
                    $basra->id => ['delivery_fee' => null],   // فارغة -> لا قاعدة
                ],
            ])
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($list, $basra) {
            $general = PriceListRule::where('price_list_id', $list->id)
                ->whereNull('to_governorate_id')->firstOrFail();

            $this->assertSame(6000, $general->delivery_fee);

            $baghdad = PriceListRule::where('price_list_id', $list->id)
                ->where('to_governorate_id', $this->baghdad()->id)->firstOrFail();

            $this->assertSame(4000, $baghdad->delivery_fee);
            $this->assertSame(1, $baghdad->priority);   // المحافظة تغلب العامة

            $this->assertNull(PriceListRule::where('price_list_id', $list->id)
                ->where('to_governorate_id', $basra->id)->first());
        });
    }

    public function test_a_new_shipment_is_priced_by_the_edited_matrix(): void
    {
        $list = Tenancy::runFor($this->company, fn () => PriceList::where('is_default', true)->firstOrFail());
        $basra = Governorate::where('code', 'BSR')->firstOrFail();

        $this->actingAs($this->owner)->put($this->host().'/pricing/'.$list->id, [
            'name' => 'التسعيرة الافتراضية', 'weight_to_grams' => 5000,
            'is_default' => 1, 'is_active' => 1,
            'rows' => [
                0 => ['delivery_fee' => 7000],
                $this->baghdad()->id => ['delivery_fee' => 3000],
            ],
        ]);

        $inBaghdad = Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'زبون بغداد',
            'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
            'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
        ], $this->owner));

        $inBasra = Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'زبون البصرة',
            'recipient_phone' => '07801234567', 'governorate_id' => $basra->id,
            'address' => 'البصرة', 'landmark' => 'قرب المستشفى', 'cod_amount' => 50_000,
        ], $this->owner));

        $this->assertSame(3000, $inBaghdad->delivery_fee);   // قاعدة بغداد
        $this->assertSame(7000, $inBasra->delivery_fee);     // القاعدة العامة
    }

    public function test_a_price_list_can_be_copied(): void
    {
        $this->actingAs($this->owner)->put($this->host().'/pricing/'.
            Tenancy::runFor($this->company, fn () => PriceList::where('is_default', true)->value('id')), [
                'name' => 'الافتراضية', 'weight_to_grams' => 5000, 'is_default' => 1, 'is_active' => 1,
                'rows' => [0 => ['delivery_fee' => 5000], $this->baghdad()->id => ['delivery_fee' => 4000]],
            ]);

        $source = Tenancy::runFor($this->company, fn () => PriceList::where('is_default', true)->firstOrFail());

        $this->actingAs($this->owner)
            ->post($this->host().'/pricing', ['name' => 'تسعيرة الجملة', 'copy_from' => $source->id])
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () {
            $copy = PriceList::where('name', 'تسعيرة الجملة')->firstOrFail();

            $this->assertSame(2, $copy->rules()->count());
            $this->assertFalse($copy->is_default);
        });
    }

    public function test_another_companys_price_list_is_not_reachable(): void
    {
        $other = $this->makeCompany('barq', 'البرق');
        $this->makeMerchant($other, 'M9001');   // ينشئ لها تسعيرتها
        $foreign = Tenancy::runFor($other, fn () => PriceList::firstOrFail());

        $this->actingAs($this->owner)
            ->get($this->host().'/pricing/'.$foreign->id)
            ->assertNotFound();
    }
}
