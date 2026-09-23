<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * العزل بين الفروع داخل الشركة الواحدة.
 *
 * الموظّف المقيَّد بفرعٍ يعمل على شحنات فرعه — وعلى ما وصل مركز فرعه
 * من فروعٍ أخرى ليوصّله: شحنةٌ من تاجرٍ في بغداد إلى زبونٍ في البصرة
 * تُوزَّع في البصرة. وما عدا ذلك لا يراه ولا يُحرّكه، ولو كتب رقمه.
 */
class BranchVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $baghdad;

    private Branch $basra;

    private Hub $basraHub;

    private Merchant $baghdadMerchant;

    private User $owner;

    private User $basraClerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->baghdadMerchant = $this->makeMerchant($this->company, 'M0001');   // فرع B1
        $this->owner = $this->makeUser($this->company);

        Tenancy::runFor($this->company, function () {
            $this->baghdad = Branch::where('code', 'B1')->firstOrFail();
            $this->basra = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة', 'is_active' => true]);
            Hub::create(['code' => 'BGD', 'name' => 'مركز بغداد', 'type' => 'main', 'branch_id' => $this->baghdad->id, 'is_active' => true]);
            $this->basraHub = Hub::create(['code' => 'BSR', 'name' => 'مركز البصرة', 'type' => 'branch', 'branch_id' => $this->basra->id, 'is_active' => true]);

            $this->basraClerk = User::create([
                'name' => 'عمليات البصرة', 'phone' => '07790000002', 'password' => 'password',
                'role' => UserRole::Operations, 'branch_id' => $this->basra->id, 'is_active' => true,
            ]);
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    /** شحنة تاجرٍ من بغداد، التقطها المندوب — وفي أيّ مركزٍ هي الآن. */
    private function baghdadShipment(?Hub $at = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($at) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->baghdadMerchant->id, 'recipient_name' => 'علي',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'البصرة', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->owner);

            app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->owner);

            if ($at) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::AtHub, $this->owner, ['hub_id' => $at->id]);
            }

            return $shipment->refresh();
        });
    }

    // ── الرؤية ──────────────────────────────────────────────────────

    public function test_a_branch_clerk_does_not_see_another_branchs_shipment(): void
    {
        $shipment = $this->baghdadShipment();

        $this->actingAs($this->basraClerk)->get($this->host().'/shipments/'.$shipment->id)->assertNotFound();
    }

    /** ما وصل مركز فرعه ليوصّله يراه ويعمل عليه — وإلا بقي بلا مَن يوزّعه. */
    public function test_a_branch_clerk_sees_what_arrived_at_their_hub_to_be_delivered(): void
    {
        $shipment = $this->baghdadShipment(at: $this->basraHub);

        $this->actingAs($this->basraClerk)->get($this->host().'/shipments/'.$shipment->id)->assertOk();

        $courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'مندوب البصرة', 'phone' => '07720000001',
            'type' => 'delivery', 'status' => 'active', 'branch_id' => $this->basra->id,
        ]));

        $this->actingAs($this->basraClerk)
            ->post($this->host().'/shipments/'.$shipment->id.'/status', [
                'status' => ShipmentStatus::OutForDelivery->value, 'courier_id' => $courier->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(ShipmentStatus::OutForDelivery, Tenancy::runFor($this->company, fn () => $shipment->fresh()->status));
    }

    // ── ما يُكتب ────────────────────────────────────────────────────

    public function test_a_branch_clerk_cannot_change_another_branchs_shipment_by_its_id(): void
    {
        $shipment = $this->baghdadShipment();

        $this->actingAs($this->basraClerk)
            ->post($this->host().'/shipments/'.$shipment->id.'/status', ['status' => ShipmentStatus::Cancelled->value])
            ->assertNotFound();

        $this->assertSame(ShipmentStatus::PickedUp, Tenancy::runFor($this->company, fn () => $shipment->fresh()->status));
    }

    /**
     * كل مسار موظّفين يربط {shipment} ويكتب يرفض مَن لا يرى الشحنة.
     *
     * يُجمَع من جدول المسارات لا من قائمة يدوية: مسارٌ يُضاف غداً يدخل
     * الفحص من تلقاء نفسه. وإزالتها من كيسٍ مستثناة: تُحكَم بالكيس لا
     * بالشحنة (موظّف المركز يُخرج من كيسه ما ليس من فرعه).
     */
    public function test_every_staff_write_route_on_a_shipment_refuses_an_unseen_one(): void
    {
        $shipment = $this->baghdadShipment();
        $open = [];

        // كل الصلاحيات: فلا يُخفي رفضُ الصلاحية (403) غيابَ فحص الرؤية
        Tenancy::runFor($this->company, fn () => $this->basraClerk
            ->forceFill(['permissions' => \App\Support\Permissions\Ability::all()])->save());

        foreach (Route::getRoutes() as $route) {
            $writes = (bool) array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);

            if (! $writes || ! in_array('staff', $route->gatherMiddleware(), true)
                || ! str_contains($route->uri(), '{shipment}') || str_contains($route->uri(), '{bag}')) {
                continue;
            }

            $uri = '/'.str_replace('{shipment}', (string) $shipment->id, $route->uri());
            $method = strtolower(collect($route->methods())->first(fn ($m) => $m !== 'HEAD'));
            $status = $this->actingAs($this->basraClerk)->{$method}($this->host().$uri, [
                'status' => ShipmentStatus::Cancelled->value, 'collected_amount' => 0, 'acknowledge' => '1', 'reason' => 'اختبار',
            ])->getStatusCode();

            if ($status !== 404) {
                $open[] = strtoupper($method)." {$uri} → {$status}";
            }
        }

        $this->assertSame([], $open, "مسارات تكتب على شحنةٍ لا يراها صاحب الطلب:\n".implode("\n", $open));
        $this->assertSame(ShipmentStatus::PickedUp, Tenancy::runFor($this->company, fn () => $shipment->fresh()->status));
    }
}
