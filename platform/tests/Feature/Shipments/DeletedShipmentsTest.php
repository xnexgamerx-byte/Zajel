<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\CreateShipment;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Models\UserGrant;
use App\Support\Permissions\Ability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «شحنات ممسوحة»: ما أُنشئ خطأً يُمسح بسببه قبل أن يصلنا، ويبقى في السلّة بمن
 * مسحه ومتى، ويُسترجع كما كان. وما وصلنا لا يُمسح.
 */
class DeletedShipmentsTest extends TestCase
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

    private function shipment(array $state = []): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($state) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->owner);

            if ($state) {
                $shipment->forceFill($state)->save();
            }

            return $shipment->refresh();
        });
    }

    public function test_a_mistaken_shipment_is_deleted_with_its_reason_and_restored_as_it_was(): void
    {
        $shipment = $this->shipment();

        $this->actingAs($this->owner)->get($this->host()."/shipments/{$shipment->id}")->assertSee('مسح الشحنة');

        $this->actingAs($this->owner)
            ->delete($this->host()."/shipments/{$shipment->id}", ['reason' => 'أُدخلت مرّتين'])
            ->assertRedirect($this->host().'/shipments/trash');

        // اختفت من القائمة ومن صفحتها
        $this->actingAs($this->owner)->get($this->host()."/shipments/{$shipment->id}")->assertNotFound();
        $this->actingAs($this->owner)->get($this->host().'/shipments')->assertDontSee('>'.$shipment->number.'<', false);

        $this->actingAs($this->owner)->get($this->host().'/shipments/trash')
            ->assertOk()->assertSee($shipment->number)->assertSee('أُدخلت مرّتين')->assertSee($this->owner->name);

        $this->actingAs($this->owner)
            ->post($this->host()."/shipments/trash/{$shipment->id}/restore")
            ->assertRedirect($this->host()."/shipments/{$shipment->id}");

        Tenancy::runFor($this->company, function () use ($shipment) {
            $fresh = Shipment::findOrFail($shipment->id);
            $this->assertNull($fresh->deleted_by_user_id);
            $this->assertNull($fresh->delete_reason);

            $this->assertSame(['deleted', 'restored'], ShipmentEvent::where('shipment_id', $shipment->id)
                ->whereIn('event_type', ['deleted', 'restored'])->orderBy('id')->pluck('event_type')->all());
            $this->assertSame(['shipment_deleted', 'shipment_restored'], AuditLog::whereIn('action', ['shipment_deleted', 'shipment_restored'])
                ->orderBy('id')->pluck('action')->all());
        });
    }

    public function test_what_reached_us_is_not_deleted(): void
    {
        foreach ([['status' => 'at_hub'], ['status' => 'delivered'], ['merchant_settled_at' => now()]] as $state) {
            $shipment = $this->shipment($state);

            $this->actingAs($this->owner)->get($this->host()."/shipments/{$shipment->id}")->assertDontSee('مسح الشحنة');

            $this->actingAs($this->owner)
                ->delete($this->host()."/shipments/{$shipment->id}", ['reason' => 'محاولة'])
                ->assertSessionHasErrors('reason');

            Tenancy::runFor($this->company, fn () => $this->assertNull($shipment->refresh()->deleted_at));
        }
    }

    public function test_a_reason_is_required(): void
    {
        $shipment = $this->shipment();

        $this->actingAs($this->owner)->delete($this->host()."/shipments/{$shipment->id}", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        Tenancy::runFor($this->company, fn () => $this->assertNull($shipment->refresh()->deleted_at));
    }

    public function test_deleting_is_an_exception_not_a_role_default(): void
    {
        $shipment = $this->shipment();
        $manager = $this->makeUser($this->company, UserRole::BranchManager);

        $this->actingAs($manager)->delete($this->host()."/shipments/{$shipment->id}", ['reason' => 'خطأ'])->assertForbidden();
        $this->actingAs($manager)->get($this->host().'/shipments/trash')->assertForbidden();

        // تُمنح لموظّفٍ بعينه كما في «صلاحية تعديل وحذف الشحنات»
        Tenancy::runFor($this->company, fn () => UserGrant::create([
            'user_id' => $manager->id, 'ability' => Ability::SHIPMENTS_DELETE,
        ]));

        $this->actingAs($manager->refresh())->delete($this->host()."/shipments/{$shipment->id}", ['reason' => 'خطأ'])
            ->assertRedirect($this->host().'/shipments/trash');
    }

    public function test_the_bin_filters_by_who_and_when(): void
    {
        $mine = $this->shipment();
        $theirs = $this->shipment();
        $other = $this->makeUser($this->company, UserRole::CompanyAdmin);

        $this->actingAs($this->owner)->delete($this->host()."/shipments/{$mine->id}", ['reason' => 'خطأ أوّل']);
        $this->actingAs($other)->delete($this->host()."/shipments/{$theirs->id}", ['reason' => 'خطأ ثانٍ']);

        $this->actingAs($this->owner)->get($this->host().'/shipments/trash?deleted_by='.$other->id)
            ->assertSee('خطأ ثانٍ')->assertDontSee('خطأ أوّل');

        $this->actingAs($this->owner)->get($this->host().'/shipments/trash?to='.now()->subDay()->toDateString())
            ->assertSee('لا شحنة ممسوحة بهذا البحث.');
    }
}
