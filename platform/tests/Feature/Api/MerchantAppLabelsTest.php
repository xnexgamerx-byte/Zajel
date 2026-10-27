<?php

namespace Tests\Feature\Api;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «طباعة الوصولات» من تطبيق التاجر (docs/plan/63): طلباته المدخلة، يحدّدها ويختار المقاس، فيُفتح
 * رابطٌ موقَّعٌ يطبعها — شحناته وحدها، وبالمقاس المختار.
 */
class MerchantAppLabelsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private Merchant $other;

    private User $user;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company, 'M0001');
        $this->other = $this->makeMerchant($this->company, 'M0002');
        $this->staff = $this->makeUser($this->company);
        $this->user = Tenancy::runFor($this->company, fn () => User::create(['name' => 'تاجر', 'phone' => '07790000001',
            'password' => 'password', 'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function headers(): array
    {
        $token = $this->postJson($this->host().'/api/v1/login', [
            'username' => $this->user->username, 'password' => 'password', 'app' => 'merchant',
        ])->assertOk()->json('token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function ship(Merchant $merchant, string $name, bool $pickedUp = false): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($merchant, $name, $pickedUp) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_name' => $name, 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 40_000,
            ], $this->staff);
            if ($pickedUp) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->staff);
            }

            return $shipment->refresh();
        });
    }

    public function test_the_merchant_prints_his_entered_orders_in_the_size_he_picks(): void
    {
        $a = $this->ship($this->merchant, 'علي');
        $b = $this->ship($this->merchant, 'زينب');
        $this->ship($this->merchant, 'استُلمت', pickedUp: true);
        $theirs = $this->ship($this->other, 'غيره');
        $headers = $this->headers();

        // المدخلة وحدها، الأحدث أوّلاً، ومقاسا التطبيق بعرضهما وطولهما (docs/plan/87)
        $this->getJson($this->host().'/api/v1/merchant/labels', $headers)->assertOk()
            ->assertJsonCount(2, 'shipments')
            ->assertJsonPath('shipments.0.name', 'زينب')
            ->assertJsonPath('shipments.1.number', $a->number)
            ->assertJsonCount(2, 'sizes')
            ->assertJsonPath('sizes.0.key', '100x100')
            ->assertJsonPath('sizes.1.key', '80x120')
            ->assertJsonPath('sizes.1.width', 80)
            ->assertJsonPath('sizes.1.height', 120);

        // شحنة غيره لا تدخل الرابط
        $url = $this->postJson($this->host().'/api/v1/merchant/labels', ['ids' => [$a->id, $b->id, $theirs->id], 'size' => '100x100'], $headers)
            ->assertCreated()->assertJsonPath('count', 2)->json('url');

        $this->get($url)->assertOk()
            ->assertSee($a->number)->assertSee($b->number)->assertDontSee($theirs->number)
            ->assertSee('100mm 100mm', false)->assertSee('ملصق ١٢٠×٨٠ ملم')->assertDontSee('أربعة في الورقة');

        // الرابط موقَّع: يُغيَّر فيه شيءٌ فلا يُفتح
        $this->get(str_replace('size=100x100', 'size=a4', $url))->assertForbidden();

        $this->postJson($this->host().'/api/v1/merchant/labels', ['ids' => [$a->id], 'size' => 'huge'], $headers)
            ->assertUnprocessable();
        $this->postJson($this->host().'/api/v1/merchant/labels', ['ids' => [$theirs->id], 'size' => '80x120'], $headers)
            ->assertUnprocessable();
    }

    public function test_the_label_has_the_company_logo_and_barcode_on_top_and_the_qr_at_the_bottom(): void
    {
        $a = $this->ship($this->merchant, 'علي');
        Tenancy::runFor($this->company, fn () => $this->company->forceFill(['logo_path' => 'logos/x.png'])->save());

        $url = $this->postJson($this->host().'/api/v1/merchant/labels', ['ids' => [$a->id], 'size' => '100x100'], $this->headers())
            ->assertCreated()->json('url');
        $html = $this->get($url)->assertOk()->getContent();

        // شعار الشركة من بياناتها (docs/plan/87) أعلى الملصق مع الباركود، ثم المستلم، ثم رمز التتبّع أسفله
        $logo = strpos($html, 'class="logo"');
        $barcode = strpos($html, 'class="barcode"');
        $recipient = strpos($html, 'المستلم');
        $qr = strpos($html, 'class="qr"');
        $this->assertNotFalse($logo);
        $this->assertStringContainsString($this->company->logoUrl(), $html);
        $this->assertTrue($logo < $barcode && $barcode < $recipient && $recipient < $qr);
        $this->assertTrue(strpos($html, 'امسح الرمز لتتبّع شحنتك') < $qr, 'الرمز يسار النصّ: آخر الصفّ في الاتجاه العربيّ');
    }

    public function test_the_printer_sorts_new_orders_into_to_print_and_printed_apart_from_in_delivery(): void
    {
        $a = $this->ship($this->merchant, 'علي');
        $b = $this->ship($this->merchant, 'زينب');
        $moving = $this->ship($this->merchant, 'استُلمت', pickedUp: true);
        $headers = $this->headers();

        // الجديدة كلّها «للطباعة»، وليست «قيد التوصيل»
        $this->getJson($this->host().'/api/v1/merchant/home', $headers)->assertOk()
            ->assertJsonPath('printer.to_print', 2)->assertJsonPath('printer.printed', 0)
            ->assertJsonPath('stats.in_delivery', 1);
        $this->getJson($this->host().'/api/v1/merchant/shipments?filter=open', $headers)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $moving->number);
        $this->getJson($this->host().'/api/v1/merchant/shipments?filter=new', $headers)->assertOk()
            ->assertJsonCount(2, 'data');

        // يطبع واحدة من التطبيق فتنتقل إلى «مطبوعة»
        $url = $this->postJson($this->host().'/api/v1/merchant/labels', ['ids' => [$a->id], 'size' => '80x120'], $headers)
            ->assertCreated()->json('url');
        $this->get($url)->assertOk();
        $first = $a->refresh()->printed_at;
        $this->assertNotNull($first);

        $this->getJson($this->host().'/api/v1/merchant/labels', $headers)->assertOk()
            ->assertJsonPath('state', 'to_print')
            ->assertJsonPath('counts.to_print', 1)->assertJsonPath('counts.printed', 1)
            ->assertJsonCount(1, 'shipments')->assertJsonPath('shipments.0.number', $b->number);
        $this->getJson($this->host().'/api/v1/merchant/labels?state=printed', $headers)->assertOk()
            ->assertJsonCount(1, 'shipments')->assertJsonPath('shipments.0.number', $a->number);

        // إعادة الطباعة لا تغيّر وقت أوّل طباعة
        $this->travel(5)->minutes();
        $this->get($url)->assertOk();
        $this->assertTrue($first->equalTo($a->refresh()->printed_at));

        // يستلمها المندوب فتخرج من «الطابعة» إلى «قيد التوصيل»
        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)->handle($a->refresh(), ShipmentStatus::PickedUp, $this->staff));
        $this->getJson($this->host().'/api/v1/merchant/home', $headers)->assertOk()
            ->assertJsonPath('printer.to_print', 1)->assertJsonPath('printer.printed', 0)
            ->assertJsonPath('stats.in_delivery', 2);
    }

    public function test_the_portal_has_the_printer_and_keeps_new_orders_out_of_the_running_list(): void
    {
        $a = $this->ship($this->merchant, 'علي');
        $moving = $this->ship($this->merchant, 'استُلمت', pickedUp: true);

        $this->actingAs($this->user)->get($this->host().'/portal/shipments')->assertOk()
            ->assertSee($moving->number)->assertDontSee($a->number)->assertSee('الطابعة');

        $this->actingAs($this->user)->get($this->host().'/portal/shipments?tab=print')->assertOk()
            ->assertSee('للطباعة')->assertSee($a->number)->assertDontSee($moving->number);

        $this->actingAs($this->user)->get($this->host().'/portal/shipments/labels?ids[]='.$a->id)->assertOk();
        $this->assertNotNull($a->refresh()->printed_at);

        $this->actingAs($this->user)->get($this->host().'/portal/shipments?tab=print')->assertOk()->assertDontSee($a->number);
        $this->actingAs($this->user)->get($this->host().'/portal/shipments?tab=print&state=printed')->assertOk()
            ->assertSee($a->number)->assertSee('أعد طباعة المحدّد');

        // البحث يجدها في «شحناتي» أيضاً
        $this->actingAs($this->user)->get($this->host().'/portal/shipments?q='.$a->number)->assertOk()->assertSee($a->number);
    }
}
