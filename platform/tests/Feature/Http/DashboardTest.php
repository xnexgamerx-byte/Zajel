<?php

namespace Tests\Feature\Http;

use App\Actions\Shipments\CreateShipment;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * لوحة اليوم: رسما الأيام السبعة يعدّان ما أُنشئ وما سُلّم في يومه، ولشركته
 * وحدها، والأيام السبعة كلّها حاضرة ولو خلا بعضها.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-26 10:00:00'); // سبت، ١ ظهراً ببغداد

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function shipment(Company $company, string $createdAt, ?string $deliveredAt = null): void
    {
        $user = $this->makeUser($company);

        $shipment = Tenancy::runFor($company, fn () => app(CreateShipment::class)->handle([
            'merchant_id'     => \App\Models\Merchant::query()->where('company_id', $company->id)->value('id'),
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد - الكرادة',
            'landmark'        => 'قرب الجامع',
            'cod_amount'      => 25_000,
        ], $user));

        DB::table('shipments')->where('id', $shipment->id)
            ->update(['created_at' => $createdAt, 'delivered_at' => $deliveredAt]);
    }

    public function test_the_week_counts_each_day_for_this_company_alone(): void
    {
        $this->shipment($this->company, '2026-09-26 08:00:00', '2026-09-26 09:00:00');
        $this->shipment($this->company, '2026-09-24 12:00:00', '2026-09-26 07:00:00');
        $this->shipment($this->company, '2026-09-24 13:00:00');
        $this->shipment($this->company, '2026-09-19 23:00:00'); // قبل الأيام السبعة

        $other = $this->makeCompany('barq', 'البرق');
        $this->makeMerchant($other, 'M0002');
        $this->shipment($other, '2026-09-26 08:30:00', '2026-09-26 09:30:00');

        $response = $this->actingAs($this->owner)
            ->get('http://zajel.'.config('zajel.tenant_domain').'/')
            ->assertOk();

        $week = collect($response->viewData('week'));

        $this->assertSame(
            ['2026-09-20', '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26'],
            $week->map(fn ($day) => $day['date']->toDateString())->all(),
        );
        $this->assertSame([0, 0, 0, 0, 2, 0, 1], $week->pluck('created')->all());
        $this->assertSame([0, 0, 0, 0, 0, 0, 2], $week->pluck('delivered')->all());

        // التسميات كلماتٌ لا حروف، واليوم باسمه؛ والتحية بساعة بغداد
        $response->assertSee('خميس')->assertSee('اليوم')->assertSee('مساء الخير، مستخدم');
    }
}
