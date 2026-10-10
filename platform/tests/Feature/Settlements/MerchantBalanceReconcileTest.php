<?php

namespace Tests\Feature\Settlements;

use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Actions\Cash\MerchantAdvances;
use App\Models\CashBox;
use App\Support\MerchantBalance;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «يكلي المتاح للتسوية ٤٤٨ بس من ادخل للكشف يطلع ٢٠٣» (docs/plan/51).
 *
 * كان «المتاح» = الإجمالي − قيد المطابقة، والإجمالي يحمل ما ليس في الكشف: كشفاً أُقفل ولم
 * يُدفع، وسلفةً تُقتطع. صار المتاح رقم الكشف نفسه، وكلّ ما في الإجمالي سواه سطرٌ باسمه،
 * فالإجمالي = قيد المطابقة + المتاح للتسوية + بانتظار الدفع − السلف، بلا فرق.
 */
class MerchantBalanceReconcileTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private Courier $courier;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany();
        $this->merchant = $this->makeMerchant($this->company);
        $this->actor = $this->makeUser($this->company);

        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001',
            'type' => 'delivery', 'status' => 'active',
            'commission_per_delivery' => 1500, 'commission_per_return' => 750,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipment(int $cod = 50_000): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id'     => $this->merchant->id,
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد',
            'landmark'        => 'قرب الجامع',
            'cod_amount'      => $cod,
        ], $this->actor));
    }

    private function walk(Shipment $shipment, array $path): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($shipment, $path) {
            $change = app(ChangeShipmentStatus::class);

            foreach ($path as $status) {
                $change->handle($shipment->refresh(), $status, $this->actor, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null,
                ]);
            }

            return $shipment->refresh();
        });
    }

    private function deliver(int $cod = 50_000): Shipment
    {
        return $this->walk($this->shipment($cod), [
            ShipmentStatus::PickedUp,
            ShipmentStatus::OutForDelivery,
            ShipmentStatus::Delivered,
        ]);
    }

    /** حالة الصورة: كشفٌ أُقفل ولم يُدفع، ومسودّةٌ مفتوحة، وواصلٌ مع مندوبٍ لم يُحاسَب */
    public function test_available_to_settle_is_exactly_what_the_statement_carries(): void
    {
        // كشفٌ أُقفل ولم يُسجَّل دفعه: ٢٤٥ ألفاً
        $this->deliver(130_000);
        $this->deliver(125_000);
        $this->settleCourierCash($this->company);
        $confirmed = Tenancy::runFor($this->company, function () {
            $s = app(BuildMerchantSettlement::class)->handle($this->merchant->refresh(), $this->actor);

            return app(PayMerchantSettlement::class)->confirm($s, $this->actor);
        });
        $this->assertSame(245_000, $confirmed->net_amount);

        // مسودّةٌ مفتوحة: ٢٠٣ آلاف بثلاث شحنات، كما في الكشف الذي فُتح
        $this->deliver(95_000);
        $this->deliver(67_000);
        $this->deliver(56_000);
        $this->settleCourierCash($this->company);
        $draft = Tenancy::runFor($this->company, fn () => app(BuildMerchantSettlement::class)
            ->handle($this->merchant->refresh(), $this->actor));
        $this->assertSame(203_000, $draft->net_amount);

        // وواصلٌ نقده ما زال مع المندوب: قيد المطابقة
        $this->deliver(64_000);
        $this->deliver(60_000);

        Tenancy::runFor($this->company, function () use ($draft) {
            $b = MerchantBalance::of($this->merchant->refresh());

            $this->assertSame(562_000, $b->total);
            $this->assertSame(114_000, $b->pending);
            // الرقم الذي يَعِد به «المتاح للتسوية» هو رقم الكشف — لا ٤٤٨
            $this->assertSame(203_000, $b->toSettle());
            $this->assertSame($draft->code, $b->draft->code);
            $this->assertSame(245_000, $b->confirmed);
            $this->assertSame(0, $b->unexplained());
            // والتاجر يرى ما يُدفع له دون انتظار: الكشف المُقفَل والمسودّة
            $this->assertSame(448_000, $b->available());
        });

        $page = $this->actingAs($this->actor)->get($this->host().'/settlements/merchants')->assertOk();
        $page->assertSeeInOrder(['data-ready', '203,000'], false)
            ->assertSeeInOrder(['بانتظار الدفع', $confirmed->code, '245,000'])
            ->assertSee('114,000')
            ->assertSee('افتح المسودّة '.$draft->code)
            ->assertDontSee('448,000')
            ->assertDontSee('data-unexplained', false);
    }

    /** السلفة تُقتطع عند الإقفال: المتاح للتسوية بعدها، والكشف يُدفع به */
    public function test_an_open_advance_is_taken_off_what_is_available(): void
    {
        Tenancy::runFor($this->company, function () {
            $box = CashBox::create(['code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 500_000, 'is_active' => true]);
            app(MerchantAdvances::class)->give($this->merchant->refresh(), 20_000, $box, $this->actor);
        });

        $this->deliver(50_000);
        $this->settleCourierCash($this->company);

        Tenancy::runFor($this->company, function () {
            $b = MerchantBalance::of($this->merchant->refresh());
            $this->assertSame([25_000, 45_000, 20_000, 25_000, 0], [$b->total, $b->ready, $b->advances, $b->toSettle(), $b->unexplained()]);

            $s = app(BuildMerchantSettlement::class)->handle($this->merchant, $this->actor);
            $s = app(PayMerchantSettlement::class)->confirm($s, $this->actor);
            $this->assertSame($b->toSettle(), $s->net_amount);

            // بعد الإقفال: السلفة سُدّدت منه، وصافيه بانتظار الدفع — والإجمالي هو هو
            $after = MerchantBalance::of($this->merchant->refresh());
            $this->assertSame([25_000, 0, 25_000, 0, 0], [$after->total, $after->advances, $after->confirmed, $after->toSettle(), $after->unexplained()]);
        });
    }

    /** قيدٌ في الرصيد لا يقابله كشفٌ ولا شحنة يظهر فرقاً للموظّف، ولا يُعرض متاحاً */
    public function test_a_balance_nothing_explains_is_flagged_not_offered(): void
    {
        $this->deliver(50_000);
        $this->settleCourierCash($this->company);
        Tenancy::runFor($this->company, fn () => $this->merchant->refresh()->forceFill(['balance' => 145_000])->save());

        Tenancy::runFor($this->company, function () {
            $b = MerchantBalance::of($this->merchant->refresh());
            $this->assertSame([45_000, 100_000, 45_000], [$b->toSettle(), $b->unexplained(), $b->available()]);
        });

        $this->actingAs($this->actor)->get($this->host().'/settlements/merchants')->assertOk()
            ->assertSee('data-unexplained', false)->assertSee('100,000');
        $this->actingAs($this->actor)->get($this->host().'/merchants/'.$this->merchant->id)->assertOk()
            ->assertSee('data-unexplained', false);
    }

    /** الحساب في التطبيق: المتاح وما ينتظر الدفع والسلف */
    public function test_the_app_balance_reports_awaiting_payment(): void
    {
        $this->deliver(50_000);
        $this->settleCourierCash($this->company);
        Tenancy::runFor($this->company, function () {
            $s = app(BuildMerchantSettlement::class)->handle($this->merchant->refresh(), $this->actor);
            app(PayMerchantSettlement::class)->confirm($s, $this->actor);

            $array = MerchantBalance::of($this->merchant->refresh())->toArray();
            $this->assertSame([45_000, 45_000, 0], [$array['available'], $array['awaiting_payment'], $array['advances']]);
        });
    }
}
