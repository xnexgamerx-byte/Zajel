<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Code128;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * وصل الشحنة: يطبعه الموظّف لما يراه، والتاجر لشحناته وحدها — فالوصل
 * يحمل اسم الزبون وهاتفه وعنوانه ومبلغه.
 */
class ShipmentLabelTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function makeShipment(?Merchant $merchant = null, array $overrides = []): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle(array_merge([
            'merchant_id'     => ($merchant ?? $this->merchant)->id,
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'الكرادة - زقاق ٧',
            'landmark'        => 'مقابل جامع الشيخ معروف',
            'cod_amount'      => 50_000,
        ], $overrides), $this->owner));
    }

    private function merchantUser(Merchant $merchant): User
    {
        return Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'حساب التاجر', 'phone' => '0779'.str_pad((string) $merchant->id, 7, '0', STR_PAD_LEFT),
            'password' => 'password', 'role' => UserRole::Merchant, 'merchant_id' => $merchant->id, 'is_active' => true,
        ]));
    }

    /** @param list<int> $ids */
    private function labels(string $path, array $ids, array $extra = []): string
    {
        return $this->host().$path.'?'.http_build_query(['ids' => $ids] + $extra);
    }

    public function test_staff_print_the_chosen_labels(): void
    {
        $first = $this->makeShipment();
        $second = $this->makeShipment(overrides: ['recipient_phone' => '07709998887', 'cod_amount' => 0]);

        $this->actingAs($this->owner)
            ->get($this->labels('/shipments/labels', [$first->id, $second->id]))
            ->assertOk()
            ->assertSee('size: 100mm 150mm', false)
            ->assertSeeInOrder([$first->number, '07801234567', 'الكرادة - زقاق ٧', '50,000',
                $second->number, '07709998887', 'مدفوعة مسبقاً'])
            ->assertSee(Code128::dataUri($first->number), false)
            ->assertSee('امسح الرمز لتتبّع شحنتك');

        $this->actingAs($this->owner)
            ->get($this->labels('/shipments/labels', [$first->id], ['size' => 'a4']))
            ->assertOk()
            ->assertSee('size: A4', false);
    }

    public function test_nothing_chosen_prints_nothing(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/shipments/labels')->assertNotFound();
    }

    public function test_a_branch_clerk_cannot_print_another_branchs_shipment(): void
    {
        $shipment = $this->makeShipment();

        $clerk = Tenancy::runFor($this->company, function () {
            $basra = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة', 'is_active' => true]);

            return User::create([
                'name' => 'عمليات البصرة', 'phone' => '07790000002', 'password' => 'password',
                'role' => UserRole::Operations, 'branch_id' => $basra->id, 'is_active' => true,
            ]);
        });

        $this->actingAs($clerk)
            ->get($this->labels('/shipments/labels', [$shipment->id]))
            ->assertNotFound();
    }

    public function test_a_merchant_prints_their_own_shipments_only(): void
    {
        $mine = $this->makeShipment();
        $theirs = $this->makeShipment($this->makeMerchant($this->company, 'M0002'),
            ['recipient_phone' => '07709998887']);
        $merchant = $this->merchantUser($this->merchant);

        $this->actingAs($merchant)
            ->get($this->labels('/portal/shipments/labels', [$mine->id, $theirs->id]))
            ->assertOk()
            ->assertSee($mine->number)
            ->assertDontSee('07709998887');

        $this->actingAs($merchant)
            ->get($this->labels('/portal/shipments/labels', [$theirs->id]))
            ->assertNotFound();

        // ولا من لوحة الموظّفين
        $this->actingAs($merchant)
            ->get($this->labels('/shipments/labels', [$mine->id]))
            ->assertForbidden();
    }

    public function test_the_new_shipments_batch_skips_what_was_picked_up(): void
    {
        $waiting = $this->makeShipment();
        $pickedUp = $this->makeShipment(overrides: ['recipient_phone' => '07709998887']);

        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)
            ->handle($pickedUp, ShipmentStatus::PickedUp, $this->owner));

        $this->actingAs($this->merchantUser($this->merchant))
            ->get($this->host().'/portal/shipments/labels?new=1')
            ->assertOk()
            ->assertSee($waiting->number)
            ->assertDontSee('07709998887');
    }
}
