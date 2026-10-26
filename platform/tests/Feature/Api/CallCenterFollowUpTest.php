<?php

namespace Tests\Feature\Api;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\MerchantMessage;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * متابعة الكول سنتر (docs/plan/83): «رسالتي الثابتة للتاجر» في التطبيق كما في الموقع، وموعد التأجيل
 * بيومه وساعته، و«ملاحظة للموظّفين» تراها الزميلات في القوائم ولا يراها التاجر.
 */
class CallCenterFollowUpTest extends TestCase
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
            $this->sara = $this->staff('سارة', '07701110011');
            $this->zainab = $this->staff('زينب', '07701110012');
        });
    }

    private function staff(string $name, string $phone): User
    {
        return User::create(['name' => $name, 'phone' => $phone, 'password' => 'password', 'role' => UserRole::CustomerService, 'is_active' => true]);
    }

    private function api(string $path): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/api/v1'.$path;
    }

    private function web(string $path): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').$path;
    }

    private function as(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson($this->api('/login'), ['username' => $user->username, 'password' => 'password', 'app' => 'callcenter'])
            ->assertOk()->json('token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function withCourier(string $name = 'علي حسين'): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($name) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => $name, 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'العنوان', 'cod_amount' => 50_000,
            ], $this->owner);
            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment, ShipmentStatus::PickedUp, $this->owner);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->owner, ['courier_id' => $this->courier->id]);

            return $shipment->refresh();
        });
    }

    private function failed(string $name = 'منى جاسم'): Shipment
    {
        $shipment = $this->withCourier($name);
        Tenancy::runFor($this->company, fn () => $shipment->forceFill([
            'status' => 'failed_attempt', 'status_changed_at' => now()->subHours(3), 'attempts_count' => 1,
        ])->save());

        return $shipment->refresh();
    }

    public function test_my_fixed_message_is_edited_in_the_app_and_filled_in_every_merchant_chat(): void
    {
        $shipment = $this->withCourier('زبون القالب');
        $sara = $this->as($this->sara);

        // القالب ما لم تكتب نصّها، وخاناته
        $this->getJson($this->api('/callcenter/merchant-message'), $sara)->assertOk()
            ->assertJsonPath('data.custom', false)
            ->assertJsonPath('data.text', MerchantMessage::TEMPLATE)
            ->assertJsonPath('data.fields.0.key', '{التاجر}');

        // في الشحنة: مملوءةً منها، فوق حقل التاجر
        $filled = $this->getJson($this->api("/callcenter/shipments/{$shipment->id}"), $sara)->assertOk()->json('data.merchant_message');
        $this->assertStringContainsString($shipment->number, $filled);
        $this->assertStringContainsString('زبون القالب', $filled);
        $this->assertStringContainsString('سارة', $filled);
        $this->assertStringNotContainsString('{الوصل}', $filled);

        // تعدّلها من التطبيق: الحقل نفسه الذي في الموقع
        $this->putJson($this->api('/callcenter/merchant-message'), ['text' => "مرحباً {التاجر}\r\nالوصل {الوصل} لم يُسلَّم"], $sara)
            ->assertOk()->assertJsonPath('data.custom', true);
        $this->assertSame("مرحباً {التاجر}\nالوصل {الوصل} لم يُسلَّم", Tenancy::runFor($this->company, fn () => $this->sara->refresh()->merchant_message));
        $this->assertSame("مرحباً {$this->merchant->business_name}\nالوصل {$shipment->number} لم يُسلَّم",
            $this->getJson($this->api("/callcenter/shipments/{$shipment->id}"), $sara)->json('data.merchant_message'));

        // ولزميلتها نصّها هي
        $this->assertStringStartsWith('السلام عليكم',
            $this->getJson($this->api("/callcenter/shipments/{$shipment->id}"), $this->as($this->zainab))->json('data.merchant_message'));

        // وفي محادثة الشحنة نفسها، وفي المعالجة
        $sara = $this->as($this->sara);
        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/merchant"), ['body' => 'سؤال'], $sara)->assertCreated();
        $conversation = $this->getJson($this->api('/callcenter/conversations?show=open'), $sara)->assertOk()->json('data.0.id');
        $this->assertStringContainsString($shipment->number,
            $this->getJson($this->api("/callcenter/conversations/{$conversation}"), $sara)->assertOk()->json('data.merchant_message'));
        $failed = $this->failed();
        $this->postJson($this->api("/callcenter/processing/{$failed->id}/claim"), [], $sara)->assertOk()
            ->assertJsonPath('data.merchant_message', fn ($v) => str_contains((string) $v, $failed->number));

        // «أرجعيها للقالب»
        $this->putJson($this->api('/callcenter/merchant-message'), ['reset' => true], $sara)->assertOk()
            ->assertJsonPath('data.custom', false)->assertJsonPath('data.text', MerchantMessage::TEMPLATE);
        $this->assertNull(Tenancy::runFor($this->company, fn () => $this->sara->refresh()->merchant_message));
    }

    public function test_postponing_from_the_status_update_keeps_the_agreed_day_and_hour(): void
    {
        $shipment = $this->withCourier('زبون الليل');
        $sara = $this->as($this->sara);
        $tomorrow = now()->addDay()->toDateString();

        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/status"),
            ['status' => 'postponed', 'postponed_until' => $tomorrow, 'postponed_time' => '21:00', 'note' => 'الزبون بالدوام'], $sara)
            ->assertOk()->assertJsonPath('message', "تم تحديث الشحنة {$shipment->number} إلى «مؤجل» إلى غداً الساعة 9:00 م.");

        $at = Tenancy::runFor($this->company, fn () => $shipment->refresh()->scheduled_at);
        $this->assertSame("{$tomorrow} 21:00", $at->format('Y-m-d H:i'));
        $this->getJson($this->api('/callcenter/board/postponed'), $sara)->assertOk()
            ->assertJsonPath('data.0.number', $shipment->number)
            ->assertJsonPath('data.0.until', $at->toIso8601String());

        // موعدٌ فات يُرفض
        $other = $this->withCourier();
        $this->postJson($this->api("/callcenter/shipments/{$other->id}/status"),
            ['status' => 'postponed', 'postponed_until' => now()->subDay()->toDateString()], $sara)
            ->assertUnprocessable()->assertJsonValidationErrors('postponed_until');
    }

    public function test_a_postponed_shipment_gets_a_new_appointment_without_changing_its_status(): void
    {
        $shipment = $this->withCourier('زبون يغيّر موعده');
        $sara = $this->as($this->sara);
        $tomorrow = now()->addDay()->toDateString();
        $later = now()->addDays(3)->toDateString();

        // ليست مؤجّلة بعد
        $this->getJson($this->api("/callcenter/shipments/{$shipment->id}"), $sara)->assertJsonPath('data.can_reschedule', false);
        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/postpone"), ['postponed_until' => $later], $sara)
            ->assertUnprocessable()->assertJsonValidationErrors('postponed_until');

        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/status"),
            ['status' => 'postponed', 'postponed_until' => $tomorrow], $sara)->assertOk();
        $this->getJson($this->api("/callcenter/shipments/{$shipment->id}"), $sara)->assertJsonPath('data.can_reschedule', true);

        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/postpone"),
            ['postponed_until' => $later, 'postponed_time' => '18:30', 'note' => 'مسافر حتى الخميس'], $sara)->assertOk();

        $shipment = Tenancy::runFor($this->company, fn () => $shipment->refresh());
        $this->assertSame(ShipmentStatus::Postponed, $shipment->status);
        $this->assertSame("{$later} 18:30", $shipment->scheduled_at->format('Y-m-d H:i'));
        $event = Tenancy::runFor($this->company, fn () => ShipmentEvent::where('shipment_id', $shipment->id)->latest('id')->first());
        $this->assertSame('edited', $event->event_type);
        $this->assertStringContainsString('موعد التأجيل: غداً ← ', $event->note);
        $this->assertStringContainsString('الساعة 6:30 م — مسافر حتى الخميس', $event->note);
    }

    public function test_postponing_in_processing_keeps_the_hour_when_one_is_given(): void
    {
        $shipment = $this->failed();
        $sara = $this->as($this->sara);
        $tonight = now()->setTime(20, 30)->format('Y-m-d H:i');

        $this->postJson($this->api("/callcenter/processing/{$shipment->id}/claim"), [], $sara)->assertOk();
        $this->postJson($this->api("/callcenter/processing/{$shipment->id}/decide"), ['action' => 'postpone', 'until' => $tonight], $sara)
            ->assertOk()->assertJsonPath('message', "عولجت {$shipment->number}: مؤجل إلى اليوم الساعة 8:30 م.");

        $this->assertSame($tonight, Tenancy::runFor($this->company, fn () => $shipment->refresh()->scheduled_at->format('Y-m-d H:i')));
    }

    public function test_a_staff_note_shows_to_colleagues_in_the_lists_and_not_to_the_merchant(): void
    {
        $postponed = $this->withCourier('زبون مؤجل');
        $failed = $this->failed('زبون معالجة');
        $sara = $this->as($this->sara);

        $this->postJson($this->api("/callcenter/shipments/{$postponed->id}/status"),
            ['status' => 'postponed', 'postponed_until' => now()->addDay()->toDateString()], $sara)->assertOk();
        $this->postJson($this->api("/callcenter/shipments/{$postponed->id}/note"), ['note' => 'اتّصلتُ ولم يردّ'], $sara)
            ->assertOk()->assertJsonPath('staff_note.text', 'اتّصلتُ ولم يردّ')->assertJsonPath('staff_note.by', 'سارة');
        $this->postJson($this->api("/callcenter/shipments/{$failed->id}/note"), ['note' => 'الزبون يردّ بعد الخامسة'], $sara)->assertOk();

        // زميلتها تراها في المؤجّل، وفي المعالجة، وفي الشحنة
        $zainab = $this->as($this->zainab);
        $this->getJson($this->api('/callcenter/board/postponed'), $zainab)->assertOk()
            ->assertJsonPath('data.0.staff_note.text', 'اتّصلتُ ولم يردّ')
            ->assertJsonPath('data.0.staff_note.by', 'سارة');
        $this->getJson($this->api('/callcenter/processing'), $zainab)->assertOk()
            ->assertJsonPath('data.0.staff_note.text', 'الزبون يردّ بعد الخامسة');
        $this->getJson($this->api("/callcenter/shipments/{$postponed->id}"), $zainab)->assertOk()
            ->assertJsonPath('data.staff_note.text', 'اتّصلتُ ولم يردّ')
            ->assertJsonPath('data.events.0.status', 'ملاحظة للموظّفين');

        // تكتب فوقها، ثم تمسحها — وكلّ ذلك في سجلّ الشحنة
        $this->postJson($this->api("/callcenter/shipments/{$postponed->id}/note"), ['note' => 'أكّد موعد الغد'], $zainab)
            ->assertOk()->assertJsonPath('staff_note.by', 'زينب');
        $this->postJson($this->api("/callcenter/shipments/{$postponed->id}/note"), ['note' => ''], $zainab)
            ->assertOk()->assertJsonPath('staff_note', null);
        $this->assertSame(3, Tenancy::runFor($this->company, fn () => ShipmentEvent::where('shipment_id', $postponed->id)->where('event_type', 'staff_note')->count()));

        // لا يراها التاجر في مسار شحنته
        $this->assertNotContains('staff_note', ShipmentEvent::MERCHANT_EVENTS);
    }

    public function test_the_site_writes_the_staff_note_and_the_postponed_hour_too(): void
    {
        $shipment = $this->withCourier();
        $this->actingAs($this->owner);
        $tomorrow = now()->addDay()->toDateString();

        $this->post($this->web("/shipments/{$shipment->id}/staff-note"), ['note' => 'اتصلت من المكتب'])->assertRedirect();
        $this->post($this->web("/shipments/{$shipment->id}/status"),
            ['status' => 'postponed', 'postponed_until' => $tomorrow, 'postponed_time' => '16:00'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $shipment = Tenancy::runFor($this->company, fn () => $shipment->refresh());
        $this->assertSame('اتصلت من المكتب', $shipment->staff_note);
        $this->assertSame("{$tomorrow} 16:00", $shipment->scheduled_at->format('Y-m-d H:i'));

        $this->get($this->web("/shipments/{$shipment->id}"))->assertOk()->assertSee('اتصلت من المكتب');

        // وفي «شحنات للمعالجة» في سطرها، كـ«تعالجها أنت»
        $failed = $this->failed('زبون الموقع');
        $this->post($this->web("/shipments/{$failed->id}/staff-note"), ['note' => 'لا يرد منذ الصباح'])->assertRedirect();
        $this->get($this->web('/processing'))->assertOk()->assertSee('لا يرد منذ الصباح');
    }

    /** مؤجّلةٌ بيد مندوبها، بموعد الغد */
    private function postponed(string $name, array $headers): Shipment
    {
        $shipment = $this->withCourier($name);
        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/status"),
            ['status' => 'postponed', 'postponed_until' => now()->addDay()->toDateString()], $headers)->assertOk();

        return Tenancy::runFor($this->company, fn () => $shipment->refresh());
    }

    public function test_a_postponed_shipment_is_delivered_or_failed_directly_without_going_back_out_by_hand(): void
    {
        $sara = $this->as($this->sara);
        $delivered = $this->postponed('زبون اتصل واستلم', $sara);

        // واصل، واصل جزئي، لم يُسلَّم — أوّل الخيارات بعد «إعادة توصيل»
        $values = collect($this->getJson($this->api("/callcenter/shipments/{$delivered->id}"), $sara)->json('data.transitions'))->pluck('value');
        $this->assertSame(['redeliver', 'delivered', 'partially_delivered', 'failed_attempt'], $values->take(4)->all());

        $this->postJson($this->api("/callcenter/shipments/{$delivered->id}/status"), ['status' => 'delivered'], $sara)
            ->assertOk()->assertJsonPath('message', "تم تحديث الشحنة {$delivered->number} إلى «واصل».");
        $delivered = Tenancy::runFor($this->company, fn () => $delivered->refresh());
        $this->assertSame(ShipmentStatus::Delivered, $delivered->status);
        $this->assertSame(50_000, (int) $delivered->collected_amount);
        $this->assertSame($this->courier->id, (int) $delivered->delivery_courier_id);

        // في سجلّها خرجت مع مندوبها ثم وصلت
        $steps = Tenancy::runFor($this->company, fn () => ShipmentEvent::where('shipment_id', $delivered->id)->orderBy('id')
            ->get(['from_status', 'to_status', 'note'])->map(fn ($e) => $e->from_status.'>'.$e->to_status)->all());
        $this->assertSame(['postponed>out_for_delivery', 'out_for_delivery>delivered'], array_slice($steps, -2));

        // لم يُسلَّم: بسببه، كما من «قيد التوصيل» — فتصير في المعالجة
        $failed = $this->postponed('زبون رفض', $sara);
        $this->postJson($this->api("/callcenter/shipments/{$failed->id}/status"), ['status' => 'failed_attempt'], $sara)
            ->assertUnprocessable()->assertJsonValidationErrors('failure_reason_id');
        $this->assertSame(ShipmentStatus::Postponed, Tenancy::runFor($this->company, fn () => $failed->refresh()->status), 'لا يُخرَج للتوصيل إن رُفض الطلب');
        $reason = Tenancy::runFor($this->company, fn () => FailureReason::availableFor($this->company->id)->where('requires_note', false)->firstOrFail());
        $this->postJson($this->api("/callcenter/shipments/{$failed->id}/status"),
            ['status' => 'failed_attempt', 'failure_reason_id' => $reason->id], $sara)->assertOk();
        $this->assertSame(ShipmentStatus::FailedAttempt, Tenancy::runFor($this->company, fn () => $failed->refresh()->status));
        $this->getJson($this->api('/callcenter/processing'), $sara)->assertOk()
            ->assertJsonFragment(['number' => $failed->number]);

        // واصل جزئي: بالمبلغ المحصَّل
        $partial = $this->postponed('زبون أخذ قطعة', $sara);
        $this->postJson($this->api("/callcenter/shipments/{$partial->id}/status"),
            ['status' => 'partially_delivered', 'collected_amount' => 20_000], $sara)->assertOk();
        $partial = Tenancy::runFor($this->company, fn () => $partial->refresh());
        $this->assertSame(ShipmentStatus::PartiallyDelivered, $partial->status);
        $this->assertSame(20_000, (int) $partial->collected_amount);
    }

    public function test_a_postponed_shipment_without_a_courier_is_not_resolved_directly(): void
    {
        $sara = $this->as($this->sara);
        $shipment = $this->postponed('بلا مندوب', $sara);
        Tenancy::runFor($this->company, fn () => $shipment->forceFill(['delivery_courier_id' => null])->save());

        $values = collect($this->getJson($this->api("/callcenter/shipments/{$shipment->id}"), $sara)->json('data.transitions'))->pluck('value');
        $this->assertNotContains('delivered', $values);
        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/status"), ['status' => 'delivered'], $sara)
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_the_site_resolves_a_postponed_shipment_directly_too(): void
    {
        $shipment = $this->postponed('زبون الموقع', $this->as($this->sara));
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner);

        $this->get($this->web("/shipments/{$shipment->id}"))->assertOk()->assertSee('<option value="delivered"', false);
        $this->post($this->web("/shipments/{$shipment->id}/status"), ['status' => 'delivered', 'collected_amount' => 50_000])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ShipmentStatus::Delivered, Tenancy::runFor($this->company, fn () => $shipment->refresh()->status));
    }
}
