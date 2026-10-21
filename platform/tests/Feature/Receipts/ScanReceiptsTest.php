<?php

namespace Tests\Feature\Receipts;

use App\Actions\Receipts\ConfirmReceipt;
use App\Actions\Returns\ReceiveReturns;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Models\ReturnBatch;
use App\Models\Shipment;
use App\Models\User;
use App\Support\ReceiptCode;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * الاستلام بمسح الورقة (docs/plan/69): وصل الحساب يأتي مع المبلغ وإيصال الراجع مع الرواجع، وعلى
 * كلٍّ رمزٌ لا يُرى في البوابة ولا في التطبيق — فالتاجر لا يؤكّد ما لم يصل بيده.
 */
class ScanReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private Merchant $other;

    private User $owner;

    private User $merchantUser;

    private User $otherUser;

    private Courier $courier;

    private Courier $pickup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company, 'M0001');
        $this->other = $this->makeMerchant($this->company, 'M0002');
        $this->owner = $this->makeUser($this->company);

        [$this->courier, $this->pickup, $this->merchantUser, $this->otherUser] = Tenancy::runFor($this->company, function () {
            $pickup = Courier::create(['code' => 'P1', 'name' => 'حيدر الاستلام', 'phone' => '07720000002', 'type' => 'pickup', 'status' => 'active']);
            $this->merchant->forceFill(['pickup_courier_id' => $pickup->id, 'portal_access' => true])->save();
            $this->other->forceFill(['portal_access' => true])->save();

            return [
                Courier::create(['code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']),
                $pickup,
                User::create(['name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password', 'role' => UserRole::Merchant,
                    'merchant_id' => $this->merchant->id, 'is_active' => true]),
                User::create(['name' => 'تاجر آخر', 'phone' => '07790000002', 'password' => 'password', 'role' => UserRole::Merchant,
                    'merchant_id' => $this->other->id, 'is_active' => true]),
            ];
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function api(string $path): string
    {
        return $this->host().'/api/v1'.$path;
    }

    /** طلب التطبيق برمزه: يُنسى من دخل البوابة قبله في الاختبار نفسه */
    private function appCall(string $method, string $path, array $headers, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, $this->api($path), $data, $headers);
    }

    private function headers(User $user): array
    {
        $token = $this->postJson($this->api('/login'), [
            'username' => $user->username, 'password' => 'password', 'app' => 'merchant',
        ])->assertOk()->json('token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function walk(Merchant $merchant, array $path): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($merchant, $path) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 50_000,
            ], $this->owner);

            foreach ($path as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null,
                ]);
            }

            return $shipment->refresh();
        });
    }

    /** كشفٌ مدفوع: المبلغ والوصل بيد التاجر */
    private function paidSettlement(Merchant $merchant): MerchantSettlement
    {
        $this->walk($merchant, [ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered]);
        $this->settleCourierCash($this->company);

        return Tenancy::runFor($this->company, function () use ($merchant) {
            $s = app(BuildMerchantSettlement::class)->handle($merchant->refresh(), $this->owner);
            $s = app(PayMerchantSettlement::class)->confirm($s, $this->owner);

            return app(PayMerchantSettlement::class)->pay($s, $this->owner, 'zaincash', 'TX-1');
        });
    }

    /** رواجع حملها مندوب الاستلام إلى التاجر بإيصال */
    private function carriedReturns(): ReturnBatch
    {
        $shipment = $this->walk($this->merchant, [ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery,
            ShipmentStatus::FailedAttempt, ShipmentStatus::Returning]);
        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)->handle([$shipment->id], $this->owner));

        $this->actingAs($this->owner)
            ->post($this->host().'/returns/pickup-courier', ['courier_id' => $this->pickup->id, 'shipment_ids' => [$shipment->id]])
            ->assertSessionHasNoErrors();

        return Tenancy::runFor($this->company, fn () => ReturnBatch::sole());
    }

    private function code(MerchantSettlement|ReturnBatch $doc): string
    {
        return Tenancy::runFor($this->company, fn () => $doc->fresh()->receiptCode());
    }

    public function test_codes_are_unguessable_and_read_from_any_scan(): void
    {
        $code = ReceiptCode::generate();

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{10}$/', $code);
        $this->assertNotSame($code, ReceiptCode::generate());
        $this->assertSame($code, ReceiptCode::normalize(strtolower(ReceiptCode::format($code))));
        $this->assertSame($code, ReceiptCode::normalize('https://zajel.example.com/rc/'.$code));
        $this->assertSame($code, ReceiptCode::normalize('  '.substr($code, 0, 5).' '.substr($code, 5).' '));
        $this->assertNull(ReceiptCode::normalize('MS000041'));
        $this->assertNull(ReceiptCode::normalize(''));
        $this->assertFalse(ReceiptCode::matches(null, $code));
        $this->assertFalse(ReceiptCode::matches($code, null));
    }

    public function test_without_the_feature_the_paper_and_the_button_stay_as_they_were(): void
    {
        $settlement = $this->paidSettlement($this->merchant);

        $this->assertFalse(Tenancy::runFor($this->company, fn () => ConfirmReceipt::required()));
        $this->actingAs($this->owner)->get($this->host()."/settlements/merchants/{$settlement->id}/print")
            ->assertOk()->assertDontSee('رمز الاستلام')->assertDontSee(ReceiptCode::format($this->code($settlement)));
        $this->actingAs($this->merchantUser)->get($this->host().'/portal/statement')->assertOk()->assertSee('استلمتُها');
        $this->actingAs($this->merchantUser)->get($this->host().'/portal/receive')->assertForbidden();
        $this->actingAs($this->merchantUser)->post($this->host()."/portal/settlements/{$settlement->id}/confirm")
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Tenancy::runFor($this->company, fn () => $settlement->fresh()->merchant_confirmed_at));
    }

    public function test_a_payout_is_confirmed_only_by_scanning_the_paper_that_came_with_it(): void
    {
        $this->setFeature($this->company, Feature::ScanReceipts);
        $settlement = $this->paidSettlement($this->merchant);
        $code = $this->code($settlement);
        $headers = $this->headers($this->merchantUser);

        // الرمز على الورقة وحدها
        $this->actingAs($this->owner)->get($this->host()."/settlements/merchants/{$settlement->id}/print")
            ->assertOk()->assertSee('رمز الاستلام')->assertSee(ReceiptCode::format($code));
        $this->actingAs($this->merchantUser)->get($this->host().'/portal/statement')->assertOk()
            ->assertSee('امسح الوصل لتأكيد استلامها')->assertDontSee('استلمتُها')->assertDontSee($code);
        $finance = $this->appCall('GET', '/merchant/finance', $headers)->assertOk()->assertJsonPath('scan_required', true);
        $this->assertStringNotContainsString($code, $finance->getContent());

        // بزرٍّ بلا ورقة، أو برمزٍ خاطئ: لا يُؤكَّد
        $this->actingAs($this->merchantUser)->post($this->host()."/portal/settlements/{$settlement->id}/confirm")
            ->assertRedirect(route('portal.receive.index'))->assertSessionHasErrors('code');
        $this->appCall('POST', "/merchant/finance/statements/{$settlement->id}/confirm", $headers, [])
            ->assertStatus(422)->assertJsonPath('scan_required', true);
        $this->appCall('POST', "/merchant/finance/statements/{$settlement->id}/confirm", $headers, ['code' => ReceiptCode::generate()])
            ->assertStatus(422);
        $this->assertNull(Tenancy::runFor($this->company, fn () => $settlement->fresh()->merchant_confirmed_at));

        // ورقة تاجرٍ آخر ليست من أوراقه
        $this->actingAs($this->otherUser)->post($this->host().'/portal/receive', ['code' => $code])
            ->assertSessionHasErrors(['code' => ConfirmReceipt::NOT_YOURS]);

        // كاميرا الهاتف تفتح رابط الورقة فيصل إلى صفحة الاستلام وفيها الكشف
        $this->actingAs($this->merchantUser)->get($this->host().'/rc/'.$code)
            ->assertRedirect(route('portal.receive.index', ['code' => $code]));
        $this->actingAs($this->merchantUser)->get($this->host().'/portal/receive?code='.urlencode('https://x.test/rc/'.$code))
            ->assertOk()->assertSee($settlement->code)->assertSee('أكّد أنها وصلتني');

        // والرمز مكتوباً باليد بأحرفٍ صغيرة وشرطة يكفي
        $this->actingAs($this->merchantUser)->post($this->host().'/portal/receive', ['code' => strtolower(ReceiptCode::format($code))])
            ->assertSessionHasNoErrors()->assertSessionHas('success', "أكّدت استلام دفعة الكشف {$settlement->code}.");
        $this->assertNotNull(Tenancy::runFor($this->company, fn () => $settlement->fresh()->merchant_confirmed_at));

        // مرّةً واحدة
        $this->actingAs($this->merchantUser)->post($this->host().'/portal/receive', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_returns_are_received_by_scanning_their_receipt_from_the_app(): void
    {
        $this->setFeature($this->company, Feature::ScanReceipts);
        $batch = $this->carriedReturns();
        $code = $this->code($batch);
        $headers = $this->headers($this->merchantUser);

        $this->actingAs($this->owner)->get($this->host()."/return-batches/{$batch->id}/print")
            ->assertOk()->assertSee(ReceiptCode::format($code));
        // نسخة التاجر من بوابته بلا رمز
        $this->actingAs($this->merchantUser)->get($this->host()."/portal/requests/returns/{$batch->id}/print")
            ->assertOk()->assertSee('نسخة التاجر')->assertDontSee(ReceiptCode::format($code));
        $this->actingAs($this->merchantUser)->get($this->host().'/portal/statement')->assertOk()
            ->assertSee('كشوفات الرواجع')->assertSee($batch->number)->assertSee('امسح الإيصال لتأكيد استلامها');

        $this->appCall('GET', '/merchant/requests', $headers)->assertOk()->assertJsonPath('scan_required', true);
        $this->appCall('POST', "/merchant/returns/{$batch->id}/confirm", $headers, [])->assertStatus(422);
        $this->actingAs($this->merchantUser)->post($this->host()."/portal/requests/returns/{$batch->id}/confirm")
            ->assertSessionHasErrors('code');

        $this->appCall('POST', '/merchant/receive', $headers, ['code' => 'https://x.test/rc/'.$code])->assertOk()
            ->assertJsonPath('type', 'returns')->assertJsonPath('id', $batch->id)
            ->assertJsonPath('message', "أكّدت استلام رواجع الإيصال {$batch->number}.");
        $this->appCall('POST', '/merchant/receive', $headers, ['code' => $code])->assertStatus(422);

        Tenancy::runFor($this->company, function () use ($batch) {
            $this->assertNotNull($batch->fresh()->received_at);
            $this->assertSame('التاجر بمسح الإيصال من التطبيق', $batch->fresh()->received_by);
        });
    }

    public function test_the_papers_link_opens_the_statement_for_staff(): void
    {
        $this->setFeature($this->company, Feature::ScanReceipts);
        $settlement = $this->paidSettlement($this->merchant);
        $batch = $this->carriedReturns();

        $this->actingAs($this->owner)->get($this->host().'/rc/'.$this->code($settlement))
            ->assertRedirect(route('doc.merchant', $settlement));
        $this->actingAs($this->owner)->get($this->host().'/rc/'.strtolower($this->code($batch)))
            ->assertRedirect(route('doc.returns', $batch));
        $this->actingAs($this->owner)->get($this->host().'/rc/'.ReceiptCode::generate())->assertNotFound();
        $this->actingAs($this->owner)->get($this->host().'/rc/not-a-code')->assertNotFound();
    }
}
