<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\CreateShipment;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «الملاحظات الثابتة» و«شحنات مرّت على مخزني» كما في المعتاد.
 */
class FixedNoteAndPassedTest extends TestCase
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

    private function create(array $extra = []): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle($extra + [
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي',
            'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
            'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
        ], $this->owner));
    }

    public function test_the_fixed_note_follows_every_new_shipment(): void
    {
        $this->actingAs($this->owner)
            ->put($this->host()."/merchants/{$this->merchant->id}", [
                'business_name' => $this->merchant->business_name, 'phone' => $this->merchant->phone,
                'governorate_id' => $this->baghdad()->id, 'settlement_cycle' => 'weekly', 'payout_method' => 'cash',
                'status' => 'active', 'fixed_note' => 'اتّصل قبل الوصول',
            ])
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, fn () => $this->merchant->refresh());

        $this->assertSame('اتّصل قبل الوصول', $this->create()->notes);
        $this->assertSame('الباب الخلفي — اتّصل قبل الوصول', $this->create(['notes' => 'الباب الخلفي'])->notes);
        // لا تتكرّر إن كُتبت في الشحنة نفسها
        $this->assertSame('اتّصل قبل الوصول، رجاءً', $this->create(['notes' => 'اتّصل قبل الوصول، رجاءً'])->notes);

        $this->actingAs($this->owner)->get($this->host()."/merchants/{$this->merchant->id}")->assertSee('اتّصل قبل الوصول');
    }

    public function test_a_shipment_that_passed_through_a_branch_shows_there_even_after_it_left(): void
    {
        [$here, $there, $hereHub, $thereHub] = Tenancy::runFor($this->company, function () {
            // فرعان غير الرئيسي: الفرع الرئيسي يرى الفروع كلّها فلا يُقاس به العزل
            $here = Branch::create(['code' => 'B3', 'name' => 'فرع الكرخ']);
            $there = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة']);

            return [$here, $there,
                Hub::create(['code' => 'H1', 'name' => 'مركز بغداد', 'type' => 'main', 'branch_id' => $here->id, 'is_active' => true]),
                Hub::create(['code' => 'H2', 'name' => 'مركز البصرة', 'type' => 'main', 'branch_id' => $there->id, 'is_active' => true])];
        });

        // من تاجرٍ في البصرة، مرّت بمخزن بغداد ثم عادت إلى البصرة
        $passed = $this->create(['branch_id' => $there->id]);
        Tenancy::runFor($this->company, function () use ($passed, $hereHub, $thereHub) {
            ShipmentEvent::create(['shipment_id' => $passed->id, 'from_status' => 'created', 'to_status' => 'at_hub',
                'event_type' => 'status_change', 'actor_type' => 'system', 'hub_id' => $hereHub->id]);
            $passed->forceFill(['status' => 'at_hub', 'hub_id' => $thereHub->id])->save();
        });
        $never = $this->create(['branch_id' => $there->id]);

        $clerk = $this->makeUser($this->company, UserRole::Operations);
        Tenancy::runFor($this->company, fn () => $clerk->forceFill(['branch_id' => $here->id])->save());

        $html = $this->actingAs($clerk)->get($this->host().'/shipments/passed')->assertOk()->getContent();

        $this->assertStringContainsString($passed->number, $html);
        $this->assertStringNotContainsString($never->number, $html);
        // لا يراها اليوم في قائمته: تُعرض ولا تُفتح
        $this->assertStringNotContainsString('/shipments/'.$passed->id.'"', $html);
        $this->assertStringContainsString('فرع البصرة', $html);

        // وصاحب الشركة يختار المخزن، ويفتحها
        $this->actingAs($this->owner)->get($this->host().'/shipments/passed?branch_id='.$there->id)
            ->assertOk()->assertDontSee($passed->number);
        $this->actingAs($this->owner)->get($this->host().'/shipments/passed?branch_id='.$here->id)
            ->assertOk()->assertSee('/shipments/'.$passed->id.'"', false);
    }
}
