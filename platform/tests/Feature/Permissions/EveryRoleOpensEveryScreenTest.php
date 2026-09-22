<?php

namespace Tests\Feature\Permissions;

use App\Actions\Returns\ReceiveReturns;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * كل دور يفتح كل شاشة — فيُمنَع أو يُخدَم، ولا يسقط.
 *
 * ثلاثة تقارير كانت تُرجع 500 لكل مدير فرع: نطاق visibleTo يكتب
 * where('branch_id') بلا اسم جدول، والتقرير يضمّ التجّار وفيهم branch_id.
 * ولم يرَها اختبارٌ لأن الاختبارات كلّها تعمل بصاحب الشركة — وصاحب
 * الشركة لا يُقيَّد بفرع فلا يمرّ بالسطر المعطوب أصلاً.
 *
 * فالحارس هنا: كل شاشة GET بلا مُعامِل في مسارات الموظّفين، بكل دور،
 * على بياناتٍ فيها ما يُضَمّ ويُجمَّع. والمقبول أيّ ردٍّ دون 500 —
 * الـ 403 منعٌ مقصود، والسقوط وحده خطأ.
 */
class EveryRoleOpensEveryScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $merchant = $this->makeMerchant($this->company, 'M0001');
        $owner = $this->makeUser($this->company);

        // بيانات تمرّ بها الاستعلامات: فرع ومركز، ومسلَّمة، وراجع على الرفّ
        Tenancy::runFor($this->company, function () use ($merchant, $owner) {
            $this->branch = Branch::where('code', 'B1')->firstOrFail();
            Hub::create(['code' => 'H1', 'name' => 'مركز', 'type' => 'main',
                'branch_id' => $this->branch->id, 'is_active' => true]);

            $courier = Courier::create([
                'code' => 'C1', 'name' => 'مندوب', 'phone' => '07720000001',
                'type' => 'delivery', 'status' => 'active', 'branch_id' => $this->branch->id,
                'commission_per_delivery' => 1500, 'commission_per_return' => 750,
            ]);

            $change = app(ChangeShipmentStatus::class);
            $make = fn () => app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_name' => 'علي',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $owner);

            $delivered = $make();
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                $change->handle($delivered->refresh(), $status, $owner, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier->id : null,
                ]);
            }

            $returned = $make();
            $change->handle($returned->refresh(), ShipmentStatus::PickedUp, $owner);
            $change->handle($returned->refresh(), ShipmentStatus::OutForDelivery, $owner, ['courier_id' => $courier->id]);
            $change->handle($returned->refresh(), ShipmentStatus::FailedAttempt, $owner, [
                'failure_reason_id' => FailureReason::where('code', 'no_answer')->value('id'),
            ]);
            $change->handle($returned->refresh(), ShipmentStatus::Returning, $owner);
            app(ReceiveReturns::class)->handle([$returned->id], $owner);
        });
    }

    /** @return array<int, string> */
    private function screens(): array
    {
        return collect(Route::getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true)
                && in_array('staff', $route->gatherMiddleware(), true)
                && ! str_contains($route->uri(), '{'))
            ->map(fn ($route) => '/'.ltrim($route->uri(), '/'))
            ->unique()
            ->values()
            ->all();
    }

    public function test_no_staff_role_crashes_on_any_screen(): void
    {
        $screens = $this->screens();

        // حارسٌ لا يفتح شاشاتٍ لا يحرس شيئاً
        $this->assertGreaterThan(30, count($screens));

        $host = 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
        $crashes = [];

        foreach ([
            UserRole::CompanyOwner, UserRole::CompanyAdmin, UserRole::BranchManager,
            UserRole::Operations, UserRole::CustomerService, UserRole::Accountant,
        ] as $role) {
            // مقيَّدٌ بفرع: هنا بالذات كان السقوط
            $user = Tenancy::runFor($this->company, fn () => User::create([
                'name' => $role->label(), 'phone' => $this->phoneFrom('role'.$role->value),
                'password' => 'password', 'role' => $role,
                'branch_id' => $this->branch->id, 'is_active' => true,
            ]));

            foreach ($screens as $uri) {
                $status = $this->actingAs($user)->get($host.$uri)->getStatusCode();

                if ($status >= 500) {
                    $crashes[] = "{$role->value} {$uri} → {$status}";
                }
            }
        }

        $this->assertSame([], $crashes, "شاشات تسقط لدورٍ بعينه:\n".implode("\n", $crashes));
    }
}
