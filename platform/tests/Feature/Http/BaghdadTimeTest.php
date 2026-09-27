<?php

namespace Tests\Feature\Http;

use App\Actions\Shipments\CreateShipment;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Reports\SqlDate;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الأوقات بتوقيت بغداد، عرضاً وتجميعاً.
 *
 * شحنةٌ أُدخلت الواحدة والنصف فجراً ببغداد هي العاشرة والنصف ليلاً في UTC من
 * اليوم السابق. بتوقيت الخادم كانت تُعرض ٢٢:٣٠ وتُعَدّ في أمس — في الشاشة
 * وفي التقرير اليومي وفي فلتر التاريخ.
 */
class BaghdadTimeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipmentAt(string $utc): Shipment
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));

        $shipment = Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id'     => \App\Models\Merchant::query()->value('id'),
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد - الكرادة',
            'landmark'        => 'قرب الجامع',
            'cod_amount'      => 25_000,
        ], $this->owner));

        $this->travelTo(CarbonImmutable::parse('2026-09-27 12:00:00', 'Asia/Baghdad'));

        return $shipment;
    }

    public function test_a_shipment_entered_after_midnight_shows_and_counts_on_the_baghdad_day(): void
    {
        $shipment = $this->shipmentAt('2026-09-26 22:30:00');   // ١:٣٠ فجر السابع والعشرين ببغداد

        $this->assertSame('2026-09-27 01:30', Tenancy::runFor($this->company,
            fn () => $shipment->fresh()->created_at->format('Y-m-d H:i')));

        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$shipment->id)
            ->assertOk()->assertSee('أُنشئت 2026-09-27 01:30');

        $listed = fn (string $day) => $this->actingAs($this->owner)
            ->get($this->host().'/shipments?from='.$day.'&to='.$day)->assertOk()
            ->viewData('shipments')->pluck('id')->all();
        $this->assertSame([$shipment->id], $listed('2026-09-27'));
        $this->assertSame([], $listed('2026-09-26'));

        $days = $this->actingAs($this->owner)->get($this->host().'/reports/daily?from=2026-09-26&to=2026-09-27')
            ->assertOk()->viewData('days');
        $this->assertSame(['2026-09-26' => 0, '2026-09-27' => 1], $days->pluck('created', 'day')->all());
    }

    public function test_hours_are_bucketed_by_the_baghdad_clock(): void
    {
        $this->shipmentAt('2026-09-27 06:15:00');   // ٩:١٥ صباحاً ببغداد

        $hours = $this->actingAs($this->owner)->get($this->host().'/reports/entries?from=2026-09-27&to=2026-09-27')
            ->assertOk()->viewData('byHour');

        $this->assertSame([9 => 1], $hours->all());
    }

    /**
     * ما كتبته النسخة السابقة (جلسة MySQL بتوقيت UTC) يُقرأ صحيحاً بعد التحديث:
     * TIMESTAMP يحفظ اللحظة، والجلسة الجديدة تعرضها ببغداد — فلا ترحيل بيانات.
     */
    public function test_times_written_in_a_utc_session_read_back_in_baghdad_on_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('جلسة المنطقة الزمنية خاصّةٌ بـ MySQL.');
        }

        $this->assertSame('+03:00', DB::selectOne('select @@session.time_zone as tz')->tz);

        $shipment = $this->shipmentAt('2026-09-27 06:00:00');

        DB::statement("set time_zone = '+00:00'");
        DB::table('shipments')->where('id', $shipment->id)->update(['created_at' => '2026-09-26 22:30:00']);
        DB::statement('set time_zone = ?', [config('database.connections.mysql.timezone')]);

        $this->assertSame('2026-09-27 01:30', Tenancy::runFor($this->company,
            fn () => $shipment->fresh()->created_at->format('Y-m-d H:i')));
        $this->assertSame('2026-09-27', DB::table('shipments')->where('id', $shipment->id)
            ->selectRaw(SqlDate::day('created_at').' as day')->value('day'));
    }
}
