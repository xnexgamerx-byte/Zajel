<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «شحنات للمعالجة»: المحاولة الفاشلة تنتظر قرار المتابعة، والقرار يُسجَّل بمن
 * اتّخذه وبعد كم.
 */
class ProcessingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'مندوب', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function failed(array $state = []): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($state) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->owner);

            $shipment->forceFill($state + [
                'status' => 'failed_attempt', 'delivery_courier_id' => $this->courier->id,
                'status_changed_at' => now()->subHours(30), 'attempts_count' => 1,
            ])->save();

            return $shipment->refresh();
        });
    }

    public function test_the_queue_lists_failed_attempts_oldest_first(): void
    {
        $newer = $this->failed(['status_changed_at' => now()->subHour()]);
        $older = $this->failed();

        $this->actingAs($this->owner)->get($this->host().'/processing')
            ->assertOk()
            ->assertSeeInOrder([$older->number, $newer->number])
            ->assertSee('تنتظر منذ يوم واحد');
    }

    /** «الزبون اتّصل بخصوص الوصل كذا»: يُوجَد برقمه، أو بمندوبه، والشارة تعدّ الكلّ */
    public function test_the_queue_is_searched_by_receipt_and_filtered_by_courier(): void
    {
        $this->failed();
        $wanted = $this->failed();
        $other = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C2', 'name' => 'مندوب ثانٍ', 'phone' => '07720000002', 'type' => 'delivery', 'status' => 'active',
        ]));
        $his = $this->failed(['delivery_courier_id' => $other->id]);

        $found = fn (string $query) => $this->actingAs($this->owner)->get($this->host().'/processing?'.$query)
            ->assertOk()->assertViewHas('pendingCount', 3)->viewData('shipments')->pluck('id')->all();

        $this->assertSame([$wanted->id], $found('q='.$wanted->number));
        $this->assertSame([$his->id], $found('courier_id='.$other->id));
        $this->actingAs($this->owner)->get($this->host().'/processing?q=999999')->assertSee('لا شحنة للمعالجة تطابق بحثك.');
    }

    /** القرار سطرٌ واحد في سجلّ الشحنة، وصفحتها تقول بمَ يُقيَّد الراجع */
    public function test_the_decision_shows_once_in_the_shipment_history(): void
    {
        $shipment = $this->failed();

        $this->actingAs($this->owner)
            ->post($this->host()."/processing/{$shipment->id}", ['action' => 'return', 'note' => 'رفض الاستلام'])
            ->assertSessionHas('success');

        $page = $this->actingAs($this->owner)->get($this->host().'/shipments/'.$shipment->id)->assertOk();

        $this->assertSame(1, substr_count($page->getContent(), 'معالجة — رفض الاستلام'));
        $page->assertSee('أجرة الراجع عند تسليمه للتاجر');
    }

    public function test_redeliver_goes_back_out_with_its_courier_and_is_recorded(): void
    {
        $shipment = $this->failed();

        $this->actingAs($this->owner)
            ->post($this->host()."/processing/{$shipment->id}", ['action' => 'redeliver', 'note' => 'قال: بعد العصر'])
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($shipment) {
            $fresh = $shipment->refresh();
            $this->assertSame(ShipmentStatus::OutForDelivery, $fresh->status);
            $this->assertSame($this->courier->id, $fresh->delivery_courier_id);

            $event = ShipmentEvent::where('shipment_id', $shipment->id)->where('event_type', 'processed')->sole();
            $this->assertSame('redeliver', $event->meta['action']);
            $this->assertSame('قال: بعد العصر', $event->meta['said']);
            $this->assertGreaterThanOrEqual(30 * 60 - 1, $event->meta['waited_minutes']);
            $this->assertSame($this->owner->id, $event->actor_id);
        });

        $this->actingAs($this->owner)->get($this->host().'/processing?tab=done')
            ->assertOk()->assertSee('إعادة توصيل')->assertSee('قال: بعد العصر')->assertSee('يوم واحد');
    }

    public function test_postpone_needs_a_date_and_keeps_it(): void
    {
        $shipment = $this->failed();

        $this->actingAs($this->owner)->post($this->host()."/processing/{$shipment->id}", ['action' => 'postpone', 'until' => ''])
            ->assertSessionHasErrors('until');

        $this->actingAs($this->owner)->post($this->host()."/processing/{$shipment->id}", [
            'action' => 'postpone', 'until' => now()->addDays(2)->toDateString(),
        ])->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($shipment) {
            $fresh = $shipment->refresh();
            $this->assertSame(ShipmentStatus::Postponed, $fresh->status);
            $this->assertSame(now()->addDays(2)->toDateString(), $fresh->scheduled_at->toDateString());
        });
    }

    public function test_return_and_twice_processed(): void
    {
        $shipment = $this->failed();

        $this->actingAs($this->owner)->post($this->host()."/processing/{$shipment->id}", ['action' => 'return'])
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, fn () => $this->assertSame(ShipmentStatus::Returning, $shipment->refresh()->status));

        // نقرةٌ ثانية من صفحةٍ قديمة لا تعالجها مرّتين
        $this->actingAs($this->owner)->post($this->host()."/processing/{$shipment->id}", ['action' => 'redeliver'])
            ->assertSessionHasErrors('action');

        Tenancy::runFor($this->company, fn () => $this->assertSame(1,
            ShipmentEvent::where('shipment_id', $shipment->id)->where('event_type', 'processed')->count()));
    }

    public function test_without_a_courier_redelivery_waits_in_the_warehouse(): void
    {
        $shipment = $this->failed(['delivery_courier_id' => null]);

        $this->actingAs($this->owner)->post($this->host()."/processing/{$shipment->id}", ['action' => 'redeliver']);

        Tenancy::runFor($this->company, fn () => $this->assertSame(ShipmentStatus::AtHub, $shipment->refresh()->status));
    }

    public function test_it_needs_the_status_ability(): void
    {
        $agent = $this->makeUser($this->company, UserRole::CustomerService);
        $shipment = $this->failed();

        $this->actingAs($agent)->get($this->host().'/processing')->assertForbidden();
        $this->actingAs($agent)->post($this->host()."/processing/{$shipment->id}", ['action' => 'return'])->assertForbidden();
    }
}
