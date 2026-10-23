<?php

namespace Tests\Feature\Reports;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\ProcessFailedAttempt;
use App\Actions\Shipments\ShipmentTickets;
use App\Actions\Support\Converse;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «أداء الموظّفين» (docs/plan/74): ما حسمه كلّ موظّفٍ وما هو بالضبط، والعدد أسبوعاً بأسبوع.
 */
class StaffPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private Courier $courier;

    private User $owner;

    private User $sara;

    private User $zainab;

    protected function setUp(): void
    {
        parent::setUp();

        // داخل ساعات المراسلة (docs/plan/39)
        $this->travelTo(now()->setTime(12, 0));
        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
        Tenancy::runFor($this->company, function () {
            $this->courier = Courier::create([
                'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001',
                'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500,
            ]);
            $this->sara = $this->agent('سارة', '07701110011');
            $this->zainab = $this->agent('زينب', '07701110012');
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function agent(string $name, string $phone): User
    {
        return User::create([
            'name' => $name, 'phone' => $phone, 'password' => 'password',
            'role' => UserRole::CustomerService, 'is_active' => true,
        ]);
    }

    private function withCourier(): Shipment
    {
        return Tenancy::runFor($this->company, function () {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'العنوان', 'cod_amount' => 50_000,
            ], $this->owner);
            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment, ShipmentStatus::PickedUp, $this->owner);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->owner, ['courier_id' => $this->courier->id]);

            return $shipment->refresh();
        });
    }

    private function ticket(): ShipmentTicket
    {
        $shipment = $this->withCourier();

        return Tenancy::runFor($this->company, fn () => app(ShipmentTickets::class)
            ->open($shipment, $this->courier, $this->owner, 'price', 45_000, 'الزبون يقول السعر 45'));
    }

    private function failed(): Shipment
    {
        $shipment = $this->withCourier();
        Tenancy::runFor($this->company, fn () => $shipment->forceFill([
            'status' => 'failed_attempt', 'status_changed_at' => now()->subHour(), 'attempts_count' => 1,
        ])->save());

        return $shipment;
    }

    public function test_each_agent_and_exactly_what_she_handled(): void
    {
        $approved = $this->ticket();
        $rejected = $this->ticket();
        $failed = $this->failed();

        Tenancy::runFor($this->company, function () use ($approved, $rejected, $failed) {
            app(ShipmentTickets::class)->approve($approved, 45_000, null, $this->sara);
            app(ShipmentTickets::class)->reject($rejected, 'السعر كما هو', $this->sara);
            app(ProcessFailedAttempt::class)->handle($failed, 'redeliver', $this->zainab, said: 'تريدها العصر');
            $conversation = app(Converse::class)->start($this->merchant, 'شحنتي تأخرت', 'وين؟', $this->owner, Converse::MERCHANT);
            app(Converse::class)->reply($conversation, 'وصلت المخزن', $this->zainab, Converse::STAFF);
        });

        $page = $this->actingAs($this->owner)->get($this->host().'/reports/staff?range=week')->assertOk()
            ->assertSee('أداء الموظّفين')->assertSee('سارة')->assertSee('زينب')
            ->assertViewHas('totals', fn ($t) => $t->opened === 2 && $t->approved === 1 && $t->rejected === 1
                && $t->processed === 1 && $t->replies === 1);

        $people = $page->viewData('people')->keyBy('name');
        $this->assertSame([1, 1, 0], [$people['سارة']->approved, $people['سارة']->rejected, $people['سارة']->processed]);
        $this->assertSame([1, 1, 1], [$people['زينب']->processed, $people['زينب']->actions['redeliver'], $people['زينب']->replies]);

        // الأسبوع الجاري: تذكرتان فُتحتا، وواحدةٌ اعتُمدت وأخرى رُفضت
        $week = $page->viewData('trend')->first();
        $this->assertSame([2, 1, 1, 1, 1], [$week->opened, $week->approved, $week->rejected, $week->processed, $week->replies]);

        // «ماذا عالج»: ما فعلته زينب بالضبط، بشحنته
        $this->actingAs($this->owner)->get($this->host().'/reports/staff?range=week&user='.$this->zainab->id)->assertOk()
            ->assertSee('ما عالجه زينب بالضبط')->assertSee('عالج: إعادة توصيل')->assertSee($failed->number)
            ->assertSee('ردّ على')->assertSee('وصلت المخزن')->assertDontSee('اعتمد تذكرة');

        // شهرياً وسنوياً
        $this->actingAs($this->owner)->get($this->host().'/reports/staff?range=year&by=year')->assertOk()
            ->assertSee(now()->format('Y'))->assertViewHas('by', 'year');
        $this->actingAs($this->owner)->get($this->host().'/reports/staff?by=month')->assertOk()
            ->assertSee(now()->format('Y-m'));
    }

    public function test_only_who_sees_reports_opens_it(): void
    {
        $this->actingAs($this->sara)->get($this->host().'/reports/staff')->assertForbidden();
    }
}
