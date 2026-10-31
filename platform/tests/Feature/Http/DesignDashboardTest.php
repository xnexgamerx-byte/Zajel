<?php

namespace Tests\Feature\Http;

use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRequest;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * docs/plan/100: رئيسيّتا «بطاقات فاتحة» و«زجاجيّ» كما في صورتيهما، بأرقام النظام، والمال لمن يرى المال.
 */
class DesignDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Shipment $onRoad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        Tenancy::runFor($this->company, function () {
            $courier = Courier::create(['code' => 'C1', 'name' => 'علي', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']);
            $make = fn () => app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'زبون', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 40_000,
            ], $this->owner);

            foreach (range(1, 3) as $i) {
                $make()->forceFill(['status' => ShipmentStatus::Delivered->value, 'delivered_at' => now()->subMinutes($i),
                    'collected_amount' => 40_000, 'status_changed_at' => now()])->save();
            }
            $make()->forceFill(['status' => ShipmentStatus::FailedAttempt->value, 'status_changed_at' => now()])->save();
            $this->onRoad = $make();
            $this->onRoad->forceFill(['status' => ShipmentStatus::OutForDelivery->value, 'delivery_courier_id' => $courier->id,
                'status_changed_at' => now(), 'staff_note' => 'اتصل بالزبون قبل الوصول', 'staff_note_at' => now()])->save();
            $this->onRoad->events()->create(['event_type' => 'status_change', 'from_status' => 'at_hub', 'to_status' => 'out_for_delivery',
                'note' => 'خرجت مع المندوب علي']);
            PickupRequest::create(['merchant_id' => $this->merchant->id, 'number' => 'PR-1', 'status' => 'pending',
                'expected_count' => 12, 'scheduled_at' => now()->addHour(), 'courier_id' => $courier->id]);
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function design(string $key): void
    {
        Tenancy::runAsPlatform(fn () => $this->company->forceFill(['settings' => ['ui' => $key]])->save());
    }

    public function test_the_soft_home_is_drawn_from_the_system(): void
    {
        $this->design('soft');

        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()
            ->assertSee('ابحث برقم الوصل…')->assertSee('شحنات هذا الشهر')->assertSee('قيد التوصيل الآن')
            ->assertSee('أداء التوصيل')->assertSee('للمعالجة')->assertSee(route('processing.index'), false)
            ->assertSee('اتصل بالزبون قبل الوصول')->assertSee($this->onRoad->number)
            ->assertSee('أكثر المحافظات')->assertSee('بغداد')->assertSee('100%')
            ->assertSee('الشحنات المسلّمة')->assertSee('مواعيد الاستلام')->assertSee('طلب الاستلام')->assertSee($this->merchant->business_name)
            // الشريط العلويّ بالبحث والحساب، والرأس القديم مخفيٌّ على الشاشة الواسعة
            ->assertSee('class="rail-actions"', false)->assertSee('app-bar-top', false)
            // وتحت اللوحة رئيسيّته كما خصّصها (docs/plan/101)
            ->assertSee('رئيسيّتي')->assertSee('خصّص الرئيسية')->assertSee(route('home.customize'), false);
    }

    /** docs/plan/101: تحت لوحة التصميم ما اختاره الموظّف من أقسام الرئيسية واختصاراتها، وحده */
    public function test_the_customised_sections_sit_under_the_design_board(): void
    {
        $this->design('glass');

        // الافتراضيّ: الأقسام كلّها إلا «اليوم» و«أين الشحنات الآن» فهما في اللوحة
        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()
            ->assertSee('شحنات متعثّرة')->assertSee('قيد التنفيذ حسب المحافظة')->assertDontSee('في المخزن والنقل');

        Tenancy::runFor($this->company, fn () => $this->owner->forceFill(['home_layout' => [
            'sections' => ['stuck'], 'alerts' => [], 'shortcuts' => ['shipments.index'],
        ]])->save());

        $this->actingAs($this->owner->fresh())->get($this->host().'/')->assertOk()
            ->assertSee('نظرة على التوصيل اليوم')->assertSee('شحنات متعثّرة')->assertDontSee('قيد التنفيذ حسب المحافظة')
            ->assertSee('aria-label="اختصاراتي"', false);
    }

    public function test_the_glass_home_is_drawn_from_the_system(): void
    {
        $this->design('glass');

        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()
            ->assertSee('نظرة على التوصيل اليوم')->assertSee('شحنات اليوم')->assertSee('قيد التوصيل')
            ->assertSee('المبالغ المستلمة')->assertSee('120,000')
            ->assertSee('مناديب على الطريق')->assertSee('تتبّع المناديب')
            ->assertSee($this->onRoad->number)->assertSee('خرجت مع المندوب علي')
            ->assertSee('الشحنات خلال الشهر')->assertSee('+ شحنة جديدة');
    }

    /** المال لمن يرى المال: من لا يراه تُعرض له المسلَّمة بدل المبالغ */
    public function test_money_stays_with_those_who_see_money(): void
    {
        $this->design('glass');
        $agent = $this->makeUser($this->company, UserRole::CustomerService);
        $this->assertFalse($agent->can('money.view'));
        $this->assertTrue($agent->can('shipments.view'));

        $this->actingAs($agent)->get($this->host().'/')->assertOk()
            ->assertSee('نظرة على التوصيل اليوم')->assertDontSee('المبالغ المستلمة')->assertDontSee('120,000')
            ->assertSee('سُلّمت هذا الأسبوع');
    }

    /** docs/plan/101: فوق شاشات كل قسمٍ نبضه — عدّاداتٌ ورسم أيّامه — في التصميمين الجديدين وحدهما */
    public function test_each_section_screen_carries_its_pulse_in_the_new_designs(): void
    {
        foreach (['soft', 'glass'] as $key) {
            $this->design($key);
            Cache::flush();

            $this->actingAs($this->owner)->get($this->host().'/shipments')->assertOk()
                ->assertSee('data-pulse="shipments"', false)->assertSee('نبض الشحنات')->assertSee('مع المناديب')->assertSee('آخر ١٤ يوماً');
            $this->actingAs($this->owner)->get(route('pickups.index'))->assertOk()
                ->assertSee('نبض الطلبات')->assertSee('استلامٌ ينتظر');
            $this->actingAs($this->owner)->get(route('processing.index'))->assertOk()->assertSee('data-pulse=', false);
            $this->actingAs($this->owner)->get(route('couriers.cash'))->assertOk()
                ->assertSee('نبض المال')->assertSee('نقدٌ بيد المندوبين');
            // وشاشةٌ خارج القوائم بقسمها من مسارها: المناديب في التوصيل
            $this->actingAs($this->owner)->get(route('couriers.index'))->assertOk()->assertSee('نبض التوصيل');
            // والرئيسية بلوحتها لا بنبض
            $this->actingAs($this->owner)->get($this->host().'/')->assertOk()->assertDontSee('data-pulse=', false);
        }

        // «نبض» كما كان: لا شريط
        $this->design('nabd');
        $this->actingAs($this->owner)->get($this->host().'/shipments')->assertOk()->assertDontSee('data-pulse=', false);
    }

    public function test_nabd_keeps_its_own_home(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()
            ->assertSee('خصّص الرئيسية')->assertDontSee('نظرة على التوصيل اليوم')->assertDontSee('class="rail-actions"', false);
    }
}
