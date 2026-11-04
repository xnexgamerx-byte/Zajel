<?php

namespace Tests\Feature\Portal;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyFeature;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تعديل التاجر لشحنته (docs/plan/113): كلّها قبل الاستلام والأجرة من التسعيرة، ولا شيء بعد الاستلام
 * (docs/plan/118) ولا لشحنة غيره — من البوابة والتطبيق.
 */
class MerchantEditShipmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $user;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company, 'M0001');
        $this->staff = $this->makeUser($this->company);
        $this->user = $this->merchantUser($this->merchant, '07790000001');
        $this->setFeature($this->company, Feature::MerchantEdit);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function merchantUser(Merchant $merchant, string $phone): User
    {
        return Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر '.$merchant->code, 'phone' => $phone, 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $merchant->id, 'is_active' => true,
        ]));
    }

    private function shipment(?Merchant $merchant = null): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => ($merchant ?? $this->merchant)->id, 'recipient_name' => 'زبون',
            'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
            'city_id' => $this->area('المنصور'), 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
        ], $this->staff));
    }

    private function move(Shipment $shipment, ShipmentStatus ...$statuses): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($shipment, $statuses) {
            $courier = Courier::firstOrCreate(['code' => 'C1'],
                ['name' => 'مندوب', 'phone' => '07721112222', 'type' => 'delivery', 'status' => 'active']);
            foreach ($statuses as $status) {
                app(ChangeShipmentStatus::class)->handle(Shipment::findOrFail($shipment->id), $status, $this->staff,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier->id : null]);
            }

            return Shipment::findOrFail($shipment->id);
        });
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'recipient_name' => 'زينب', 'recipient_phone' => '07712345678', 'governorate_id' => $this->baghdad()->id,
            'city_id' => $this->area('المنصور'), 'landmark' => 'قرب السوق', 'pieces_count' => 2, 'cod_amount' => 60_000,
            'notes' => 'اتصل قبل الوصول',
        ], $overrides);
    }

    private function token(): string
    {
        return $this->postJson($this->host().'/api/v1/login', [
            'username' => $this->user->username, 'password' => 'password', 'app' => 'merchant',
        ])->assertOk()->json('token');
    }

    public function test_before_pickup_the_merchant_edits_everything_and_the_fee_comes_from_the_price_list(): void
    {
        $shipment = $this->shipment();
        // أجرةٌ وضعها موظّف: تبقى ما دامت الوجهة لم تتغيّر
        Tenancy::runFor($this->company, fn () => $shipment->forceFill(['delivery_fee' => 1234])->save());

        $this->actingAs($this->user)->get($this->host().'/portal/shipments/'.$shipment->id)->assertOk()->assertSee('تعديل');
        $this->get($this->host().'/portal/shipments/'.$shipment->id.'/edit')->assertOk()
            ->assertSee('لم يستلمها المندوب بعد')->assertSee('07801234567')->assertSee('name="cod_amount"', false);

        $this->put($this->host().'/portal/shipments/'.$shipment->id, $this->form(['delivery_fee' => 0, 'merchant_id' => 999]))
            ->assertRedirect('/portal/shipments/'.$shipment->id)->assertSessionHas('success');

        $fresh = Tenancy::runFor($this->company, fn () => $shipment->fresh());
        $this->assertSame('زينب', $fresh->recipient_name);
        $this->assertSame('07712345678', $fresh->recipient_phone);
        $this->assertSame(60_000, (int) $fresh->cod_amount);
        $this->assertSame(2, (int) $fresh->pieces_count);
        $this->assertSame(1234, (int) $fresh->delivery_fee, 'لا يسعّر لنفسه، ولا تتغيّر الأجرة بلا تغيّر الوجهة');
        $this->assertSame($this->merchant->id, (int) $fresh->merchant_id);
        $this->assertSame(60_000 - (int) $fresh->total_fees, (int) $fresh->merchant_due);

        // تغيّرت المنطقة: الأجرة من التسعيرة
        $this->put($this->host().'/portal/shipments/'.$shipment->id, $this->form(['city_id' => $this->area('الكرادة')]))
            ->assertSessionHasNoErrors();
        $this->assertNotSame(1234, (int) Tenancy::runFor($this->company, fn () => $shipment->fresh()->delivery_fee));

        $event = Tenancy::runFor($this->company, fn () => ShipmentEvent::where('shipment_id', $shipment->id)->where('event_type', 'edited')->first());
        $this->assertStringStartsWith('عدّلها التاجر', $event->note);
        $this->assertSame($this->user->id, $event->actor_id);
    }

    public function test_after_pickup_nothing_changes_from_the_portal_or_the_app(): void
    {
        $shipment = $this->move($this->shipment(), ShipmentStatus::PickedUp);
        $locked = 'استلمها المندوب: لا تُعدَّل بعد الاستلام. لتغيير شيءٍ فيها راسل الدعم.';

        // التطبيق أوّلاً (Bearer قبل جلسة البوابة): لا وضع تعديل، والحفظ يُردّ بالسبب
        $headers = ['Authorization' => 'Bearer '.$this->token()];
        $this->getJson($this->host().'/api/v1/merchant/shipments/'.$shipment->id, $headers)->assertOk()
            ->assertJsonPath('edit.mode', null)->assertJsonPath('edit.fields', [])->assertJsonPath('edit.locked', $locked)
            ->assertJsonPath('edit.deletable', false);
        $this->putJson($this->host().'/api/v1/merchant/shipments/'.$shipment->id, ['recipient_phone' => '07700001111'], $headers)
            ->assertUnprocessable()->assertJsonPath('message', $locked);

        // والبوابة: لا زرّ تعديل، والصفحة تعود بالسبب، والحفظ لا يغيّر شيئاً
        $this->actingAs($this->user)->get($this->host().'/portal/shipments/'.$shipment->id)->assertOk()
            ->assertDontSee('/portal/shipments/'.$shipment->id.'/edit', false);
        $this->get($this->host().'/portal/shipments/'.$shipment->id.'/edit')
            ->assertRedirect('/portal/shipments/'.$shipment->id)->assertSessionHasErrors('shipment');
        $this->put($this->host().'/portal/shipments/'.$shipment->id, $this->form())
            ->assertRedirect()->assertSessionHasErrors('shipment');

        $fresh = Tenancy::runFor($this->company, fn () => $shipment->fresh());
        $this->assertSame('07801234567', $fresh->recipient_phone);
        $this->assertSame('زبون', $fresh->recipient_name);
        $this->assertSame(50_000, (int) $fresh->cod_amount);
        $this->assertNull($fresh->notes);
    }

    public function test_a_settled_shipment_is_locked(): void
    {
        $delivered = $this->move($this->shipment(), ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered);
        $headers = ['Authorization' => 'Bearer '.$this->token()];

        $this->getJson($this->host().'/api/v1/merchant/shipments/'.$delivered->id, $headers)->assertOk()
            ->assertJsonPath('edit.mode', null)->assertJsonPath('edit.locked', 'لا تُعدَّل شحنةٌ بعد تسليمها أو إرجاعها أو إلغائها.');
        $this->putJson($this->host().'/api/v1/merchant/shipments/'.$delivered->id, ['recipient_phone' => '07700001111'], $headers)
            ->assertUnprocessable();

        $this->actingAs($this->user)->get($this->host().'/portal/shipments/'.$delivered->id.'/edit')
            ->assertRedirect('/portal/shipments/'.$delivered->id)->assertSessionHasErrors('shipment');
    }

    public function test_another_merchants_shipment_is_not_found_and_the_feature_is_off_until_opened(): void
    {
        $other = $this->makeMerchant($this->company, 'M0002');
        $theirs = $this->shipment($other);
        $headers = ['Authorization' => 'Bearer '.$this->token()];

        $this->actingAs($this->user)->get($this->host().'/portal/shipments/'.$theirs->id.'/edit')->assertNotFound();
        $this->put($this->host().'/portal/shipments/'.$theirs->id, $this->form())->assertNotFound();
        $this->putJson($this->host().'/api/v1/merchant/shipments/'.$theirs->id, $this->form(), $headers)->assertNotFound();
        $this->assertSame('07801234567', Tenancy::runFor($this->company, fn () => $theirs->fresh()->recipient_phone));

        // مطفأة: لا زرّ ولا مسار
        $mine = $this->shipment();
        $this->setFeature($this->company, Feature::MerchantEdit, false);
        $this->get($this->host().'/portal/shipments/'.$mine->id)->assertOk()->assertDontSee('portal/shipments/'.$mine->id.'/edit');
        $this->get($this->host().'/portal/shipments/'.$mine->id.'/edit')->assertForbidden();
        $this->getJson($this->host().'/api/v1/merchant/shipments/'.$mine->id, $headers)->assertJsonPath('edit', null);
        $this->putJson($this->host().'/api/v1/merchant/shipments/'.$mine->id, $this->form(), $headers)->assertForbidden();
    }

    public function test_the_migration_opens_merchant_edit_for_every_current_company(): void
    {
        $closed = $this->makeCompany('barq', 'البرق');
        $this->setFeature($closed, Feature::MerchantEdit, false);

        $migration = require database_path('migrations/2026_01_03_010600_open_merchant_edit_for_current_companies.php');
        $migration->up();
        $migration->up();

        $this->assertTrue($this->company->fresh()->hasFeature(Feature::MerchantEdit));
        $this->assertTrue($closed->fresh()->hasFeature(Feature::MerchantEdit));
        $this->assertSame(1, Tenancy::runFor($closed, fn () => CompanyFeature::where('feature', 'merchant_edit')->whereNull('ends_at')->count()));
        $this->assertFalse($this->makeCompany('saree', 'السريع')->hasFeature(Feature::MerchantEdit));
    }

    /** «احذف الشحنة» من التعديل (docs/plan/117) */
    public function test_the_merchant_deletes_a_shipment_that_has_not_reached_us_yet(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->token()];

        // جديدة: يُحذف من التطبيق، وتذهب إلى الشحنات الممسوحة بسببه
        $new = $this->shipment();
        $this->getJson($this->host().'/api/v1/merchant/shipments/'.$new->id, $headers)->assertJsonPath('edit.deletable', true);
        $this->deleteJson($this->host().'/api/v1/merchant/shipments/'.$new->id, ['reason' => 'ألغى الزبون'], $headers)
            ->assertOk()->assertJsonPath('message', 'حُذفت الشحنة '.$new->number.'.');
        $trashed = Tenancy::runFor($this->company, fn () => Shipment::onlyTrashed()->find($new->id));
        $this->assertSame('حذفها التاجر — ألغى الزبون', $trashed->delete_reason);
        $this->assertSame($this->user->id, (int) $trashed->deleted_by_user_id);

        // استلمها المندوب: لا يُحذف
        $picked = $this->move($this->shipment(), ShipmentStatus::PickedUp);
        $this->getJson($this->host().'/api/v1/merchant/shipments/'.$picked->id, $headers)->assertJsonPath('edit.deletable', false);
        $this->deleteJson($this->host().'/api/v1/merchant/shipments/'.$picked->id, [], $headers)->assertUnprocessable();
        $this->assertNotNull(Tenancy::runFor($this->company, fn () => Shipment::find($picked->id)));

        // وشحنة تاجرٍ آخر لا تُرى
        $theirs = $this->shipment($this->makeMerchant($this->company, 'M0002'));
        $this->deleteJson($this->host().'/api/v1/merchant/shipments/'.$theirs->id, [], $headers)->assertNotFound();

        // ومن البوابة: زرّها في التعديل، وما استلمه المندوب لا يُفتح تعديله ولا يُحذف
        $portal = $this->shipment();
        $this->actingAs($this->user)->get($this->host().'/portal/shipments/'.$portal->id.'/edit')->assertSee('احذف الشحنة');
        $this->delete($this->host().'/portal/shipments/'.$portal->id)->assertRedirect('/portal/shipments');
        $this->assertNull(Tenancy::runFor($this->company, fn () => Shipment::find($portal->id)));
        $this->get($this->host().'/portal/shipments/'.$picked->id.'/edit')->assertRedirect('/portal/shipments/'.$picked->id);
        $this->delete($this->host().'/portal/shipments/'.$picked->id)->assertSessionHasErrors();
        $this->assertNotNull(Tenancy::runFor($this->company, fn () => Shipment::find($picked->id)));
        $this->delete($this->host().'/portal/shipments/'.$theirs->id)->assertNotFound();

        // ومطفأةً ميزة التعديل: لا حذف
        $this->setFeature($this->company, Feature::MerchantEdit, false);
        $this->delete($this->host().'/portal/shipments/'.$this->shipment()->id)->assertForbidden();
    }
}
