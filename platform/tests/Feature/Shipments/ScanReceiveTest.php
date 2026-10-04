<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
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
 * «استلام وصولات في كل المراحل»: المسح يسأل ولا يغيّر، والحفظ يُدخل كل طردٍ
 * المخزن بالانتقال الذي تحتاجه مرحلته، ويتخطّى ما لا يدخل بسببه.
 */
class ScanReceiveTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Hub $hub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        $this->hub = Tenancy::runFor($this->company, function () {
            $main = Branch::where('code', 'B1')->firstOrFail();
            $main->forceFill(['is_main' => true])->save();

            return Hub::create(['code' => 'H1', 'name' => 'مخزن بغداد', 'type' => 'main',
                'branch_id' => $main->id, 'is_active' => true]);
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipment(array $state = [], array $input = []): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($state, $input) {
            $shipment = app(CreateShipment::class)->handle($input + [
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

    public function test_lookup_finds_by_number_or_barcode_and_masks_the_phone(): void
    {
        $shipment = $this->shipment(['barcode' => 'ZJ-777'], ['recipient_phone' => '07701112233']);

        foreach ([$shipment->number, 'ZJ-777'] as $typed) {
            $this->actingAs($this->owner)
                ->getJson($this->host().'/shipments/scan/lookup?number='.urlencode($typed))
                ->assertOk()
                ->assertJson([
                    'id' => $shipment->id, 'number' => $shipment->number, 'status' => 'جديد',
                    'amount' => 50000, 'phone' => '••••••••233',
                ]);
        }

        $this->actingAs($this->owner)->getJson($this->host().'/shipments/scan/lookup?number=NOPE')
            ->assertNotFound()->assertJson(['error' => 'لا وصل برقم NOPE.']);
    }

    public function test_lookup_reads_the_qr_on_the_label_and_refuses_a_forged_one(): void
    {
        $shipment = $this->shipment();
        $link = Tenancy::runFor($this->company, fn () => \App\Support\Tracking::url($shipment));

        $this->actingAs($this->owner)
            ->getJson($this->host().'/shipments/scan/lookup?number='.urlencode($link))
            ->assertOk()
            ->assertJson(['id' => $shipment->id, 'number' => $shipment->number]);

        // رقمنا ببصمةٍ ليست له: رمزٌ من شركةٍ أخرى أو مزوَّر
        $forged = preg_replace('~/[a-f0-9]{16}$~', '/0000000000000000', $link);

        $this->actingAs($this->owner)
            ->getJson($this->host().'/shipments/scan/lookup?number='.urlencode($forged))
            ->assertNotFound()
            ->assertJson(['error' => 'رمز QR هذا ليس لوصلٍ من وصولاتنا.']);
    }

    public function test_lookup_does_not_reveal_another_branchs_shipment(): void
    {
        // شحنة الفرع الرئيسي، وموظّفٌ في فرع البصرة: الرئيسي وحده يرى الفروع كلّها
        $other = Tenancy::runFor($this->company, fn () => Branch::create(['code' => 'B2', 'name' => 'فرع البصرة']));
        $theirs = $this->shipment();

        $clerk = $this->makeUser($this->company, UserRole::Operations);
        Tenancy::runFor($this->company, fn () => $clerk->forceFill(['branch_id' => $other->id])->save());

        $this->actingAs($clerk)->getJson($this->host().'/shipments/scan/lookup?number='.$theirs->number)->assertNotFound();

        // ولا يُستلم باسمه ولو أُرسل معرّفه
        $this->actingAs($clerk)->post($this->host().'/shipments/scan/receive', ['shipment_ids' => [$theirs->id]]);
        Tenancy::runFor($this->company, fn () => $this->assertSame(ShipmentStatus::Created, $theirs->refresh()->status));
    }

    public function test_every_stage_enters_the_warehouse_its_own_way(): void
    {
        $dropped = $this->shipment();                                        // أحضرها التاجر
        $picked = $this->shipment(['status' => 'picked_up']);
        $back = $this->shipment(['status' => 'out_for_delivery']);
        $return = $this->shipment(['status' => 'returning']);                // راجعٌ بيد المندوب
        $shelf = $this->shipment(['status' => 'at_hub']);
        $done = $this->shipment(['status' => 'delivered', 'collected_amount' => 50_000]);

        $this->actingAs($this->owner)
            ->post($this->host().'/shipments/scan/receive', [
                'shipment_ids' => [$dropped->id, $picked->id, $back->id, $return->id, $shelf->id, $done->id],
            ])
            ->assertRedirect($this->host().'/shipments/scan')
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'استُلمت في المخزن 4 شحنات')
                && str_contains($m, "{$shelf->number} (بالمخزن سلفاً)")
                && str_contains($m, "{$done->number} (واصل)"));

        Tenancy::runFor($this->company, function () use ($dropped, $picked, $back, $return, $done) {
            foreach ([$dropped, $picked, $back] as $s) {
                $s->refresh();
                $this->assertSame(ShipmentStatus::AtHub, $s->status, $s->number);
                $this->assertSame($this->hub->id, $s->hub_id);
            }

            // من عند التاجر إلى المخزن مرّت بـ«تم الاستلام»: سجلّها يقول ما جرى
            $this->assertSame(['created', 'picked_up', 'at_hub'],
                ShipmentEvent::where('shipment_id', $dropped->id)->where('event_type', 'status_change')
                    ->orderBy('id')->pluck('to_status')->all());

            $return->refresh();
            $this->assertSame(ShipmentStatus::Returning, $return->status);
            $this->assertNotNull($return->return_received_at);

            $this->assertSame(ShipmentStatus::Delivered, $done->refresh()->status);
        });
    }

    public function test_the_screen_offers_only_what_the_user_may_do(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/shipments/scan')
            ->assertOk()->assertSee('استلم الكلّ في المخزن')->assertSee('إسناد وإخراج للتوصيل');

        // الكول سنتر يغيّر الحالة (يستلم في المخزن) ولا يُسند للمناديب (docs/plan/30)
        $agent = $this->makeUser($this->company, UserRole::CustomerService);

        $this->actingAs($agent)->get($this->host().'/shipments/scan')
            ->assertOk()->assertSee('استلم الكلّ في المخزن')->assertDontSee('إسناد وإخراج للتوصيل');

        // والمحاسب يرى ولا يغيّر شيئاً
        $accountant = $this->makeUser($this->company, UserRole::Accountant);

        $this->actingAs($accountant)->get($this->host().'/shipments/scan')
            ->assertOk()->assertDontSee('استلم الكلّ في المخزن')->assertDontSee('إسناد وإخراج للتوصيل');

        $this->actingAs($accountant)->post($this->host().'/shipments/scan/receive', ['shipment_ids' => [1]])
            ->assertForbidden();
    }
}
