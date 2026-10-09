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
        // المحاسب لا يغيّر حالة شحنة (والكول سنتر يعالج: docs/plan/30)
        $agent = $this->makeUser($this->company, UserRole::Accountant);
        $shipment = $this->failed();

        $this->actingAs($agent)->get($this->host().'/processing')->assertForbidden();
        $this->actingAs($agent)->post($this->host()."/processing/{$shipment->id}", ['action' => 'return'])->assertForbidden();
    }

    /**
     * «إعادة توصيل» خانةٌ وحدها (docs/plan/38): ما عالجه الكول سنتر فأعاده لا يختلط بما خرج
     * أوّل مرّة — في «كل مراحل النقل»، وفي شارته، وعند المندوب. ويفشل ثانيةً فيعود للمعالجة.
     */
    public function test_redelivery_is_its_own_stage_and_clears_when_it_fails_again(): void
    {
        $shipment = $this->failed();

        $this->actingAs($this->owner)->post($this->host()."/processing/{$shipment->id}", ['action' => 'redeliver'])
            ->assertSessionHas('success');

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->status);
        $this->assertNotNull($shipment->redelivery_at);
        $this->assertTrue($shipment->isRedelivery());
        $this->assertSame('إعادة توصيل', $shipment->statusLabel());

        $count = fn (string $stage) => Tenancy::runFor($this->company, fn () => \App\Services\Shipments\ShipmentStages::apply(Shipment::query(), $stage)->count());
        $this->assertSame(1, $count('redelivery'));
        $this->assertSame(0, $count('out_for_delivery'));

        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$shipment->id)->assertOk()->assertSee('إعادة توصيل');

        // المندوب يراها «إعادة توصيل» في مهامّه
        $courierUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'مندوب', 'phone' => '07720000001', 'password' => 'password',
            'role' => UserRole::Courier, 'courier_id' => $this->courier->id, 'is_active' => true,
        ]));
        $this->actingAs($courierUser)->get($this->host().'/courier')->assertOk()->assertSee('إعادة توصيل');

        // فشلت ثانيةً: تعود للمعالجة، ولا تبقى «إعادة توصيل»
        Tenancy::runFor($this->company, fn () => app(\App\Actions\Shipments\ChangeShipmentStatus::class)
            ->handle($shipment->refresh(), ShipmentStatus::FailedAttempt, $this->owner));
        $this->assertNull($shipment->refresh()->redelivery_at);
        $this->assertSame(0, $count('redelivery'));
    }

    /**
     * «راجع مؤكد»: قرار المعالجة بالإرجاع يُكتب بصاحبه، وتُعرض الشحنة «راجع مؤكد» في خانتها
     * وعند المندوب حتى يسلّمها للمخزن — ثم تصير راجعاً على الرفّ لا شحنةً جديدة.
     */
    public function test_a_return_decision_is_a_confirmed_return_until_the_courier_hands_it_in(): void
    {
        $shipment = $this->failed();

        $this->actingAs($this->owner)->post($this->host()."/processing/{$shipment->id}", ['action' => 'return', 'note' => 'رفض الاستلام'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'راجع مؤكد'));

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Returning, $shipment->status);
        $this->assertNotNull($shipment->return_confirmed_at);
        $this->assertSame($this->owner->id, $shipment->return_confirmed_by_user_id);
        $this->assertSame('راجع مؤكد', $shipment->statusLabel());

        $count = fn (string $stage) => Tenancy::runFor($this->company, fn () => \App\Services\Shipments\ShipmentStages::apply(Shipment::query(), $stage)->count());
        $this->assertSame(1, $count('confirmed_return'));
        $this->assertSame(0, $count('return_with_courier'));

        $courierUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'مندوب', 'phone' => '07720000001', 'password' => 'password',
            'role' => UserRole::Courier, 'courier_id' => $this->courier->id, 'is_active' => true,
        ]));
        $this->actingAs($courierUser)->get($this->host().'/courier')->assertOk()
            ->assertSee('رواجع بيدك')->assertSee($shipment->number)->assertSee('راجع مؤكد');

        // سلّمها المندوب للمخزن: راجعٌ على الرفّ، لا «راجع مؤكد» ولا شحنةٌ جديدة
        Tenancy::runFor($this->company, fn () => app(\App\Actions\Returns\ReceiveReturns::class)->handle([$shipment->id], $this->owner));
        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Returning, $shipment->status);
        $this->assertFalse($shipment->isConfirmedReturnWithCourier());
        $this->assertSame(0, $count('confirmed_return'));
        $this->assertSame(1, $count('return_on_shelf'));
        $this->actingAs($courierUser)->get($this->host().'/courier')->assertOk()->assertDontSee('رواجع بيدك');
    }

    /** «تأكيد الراجع» في صفّ القرار، والقائمة تقول «راجع مؤكد» لا «راجع» */
    public function test_the_return_is_confirmed_from_the_row_and_named_so_in_the_lists(): void
    {
        $shipment = $this->failed();

        $this->actingAs($this->owner)->get($this->host().'/processing')
            ->assertOk()->assertSee('تأكيد الراجع')->assertDontSee('إرجاع للتاجر');
        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$shipment->id)
            ->assertOk()->assertSee('<option value="returning"', false)->assertSee('راجع مؤكد');
        $this->assertSame('راجع مؤكد', \App\Actions\Shipments\ChangeStatusInBulk::targets()['returning']);

        $this->actingAs($this->owner)->post($this->host()."/processing/{$shipment->id}", ['action' => 'return'])
            ->assertSessionHas('success', "عولجت {$shipment->number}: راجع مؤكد.");
        $this->assertSame('راجع مؤكد', $shipment->refresh()->statusLabel());
    }

    /** الرسالة الثابتة للتاجر: القالب مملوءاً من الشحنة، ولكل موظّفٍ نصّه */
    public function test_each_employee_has_a_pinned_message_for_the_merchant(): void
    {
        $shipment = $this->failed();
        Tenancy::runFor($this->company, fn () => $this->merchant->update(['phone' => '07711112222', 'owner_name' => 'أبو حسن']));

        $page = $this->actingAs($this->owner)->get($this->host().'/processing')->assertOk()
            ->assertDontSee('رسالتي الثابتة للتاجر</summary>', false)->assertSee('نسخ رسالة التاجر');
        $this->actingAs($this->owner)->get($this->host().'/merchant-message')->assertOk()
            ->assertSee('رسالتي الثابتة للتاجر')->assertSee('{السبب}');
        $text = \App\Support\MerchantMessage::for($shipment->load('merchant', 'governorate'), $this->owner);
        $this->assertStringContainsString($shipment->number, $text);
        // «السلام عليكم {التاجر}» باسم صاحب المتجر لا اسم المتجر
        $this->assertStringContainsString('السلام عليكم أبو حسن،', $text);
        $this->assertStringContainsString('علي', $text);
        $this->assertStringNotContainsString('{', $text);
        $page->assertSee('data-copy-text="'.e($text).'"', false)
            ->assertSee(e(\App\Support\Phone::whatsappUrl('07711112222', $text)), false);

        // نصّ الموظّف نفسه، وغيره يبقى على القالب
        $this->actingAs($this->owner)->put($this->host().'/merchant-message', ['merchant_message' => 'هلا {التاجر} ({المتجر})، الوصل {الوصل} راجع.'])
            ->assertSessionHas('success', 'حُفظت رسالتك للتاجر.');
        $this->actingAs($this->owner)->get($this->host().'/processing')
            ->assertSee(e('هلا أبو حسن ('.$this->merchant->business_name.')، الوصل '.$shipment->number.' راجع.'), false);
        $other = $this->makeUser($this->company, UserRole::CustomerService);
        $this->assertSame(\App\Support\MerchantMessage::TEMPLATE, \App\Support\MerchantMessage::templateOf($other));

        $this->actingAs($this->owner)->put($this->host().'/merchant-message', ['merchant_message' => 'x', 'reset' => 1])
            ->assertSessionHas('success', 'عادت رسالتك إلى القالب.');
        $this->assertNull($this->owner->refresh()->merchant_message);
    }

    /** «أرسل للتاجر» بلا قرار: الرسالة في محادثته عن الشحنة، والشحنة باقيةٌ للمعالجة */
    public function test_the_merchant_is_asked_before_any_decision(): void
    {
        $this->setFeature($this->company, \App\Enums\Feature::Conversations);
        $shipment = $this->failed();

        $this->actingAs($this->owner)->get($this->host().'/processing')->assertOk()->assertSee('أرسل للتاجر');

        $this->actingAs($this->owner)->post($this->host()."/processing/{$shipment->id}/ask")
            ->assertSessionHas('success');
        $this->actingAs($this->owner)->post($this->host()."/processing/{$shipment->id}/ask");

        // لم يتغيّر شيء من حالها، وهي في المعالجة تنتظر ردّه
        $this->assertSame(ShipmentStatus::FailedAttempt, $shipment->refresh()->status);
        $this->actingAs($this->owner)->get($this->host().'/processing')->assertOk()
            ->assertSee($shipment->number)->assertSee('ينتظر ردّه');

        // محادثةٌ واحدة عن الشحنة، فيها الرسالتان، وجديدةٌ عند التاجر
        Tenancy::runFor($this->company, function () use ($shipment) {
            $conversation = \App\Models\Conversation::where('shipment_id', $shipment->id)->sole();
            $this->assertTrue((bool) $conversation->merchant_unread);
            $this->assertSame(2, $conversation->messages()->count());
            $this->assertStringContainsString($shipment->number, (string) $conversation->messages()->first()->body);
            $this->assertSame(2, ShipmentEvent::where('shipment_id', $shipment->id)->where('event_type', 'merchant_asked')->count());
        });
    }

    public function test_without_conversations_the_merchant_is_reached_by_whatsapp(): void
    {
        $this->setFeature($this->company, \App\Enums\Feature::Conversations, false);
        $shipment = $this->failed();

        $this->actingAs($this->owner)->get($this->host().'/processing')->assertOk()->assertDontSee('أرسل للتاجر');
        $this->actingAs($this->owner)->post($this->host()."/processing/{$shipment->id}/ask")
            ->assertSessionHasErrors('action');
    }

    /** محادثتا التاجر والمندوب في صفّ الشحنة: يُردّ منهما ويُعاد إلى الصفّ نفسه، ومن كتب يُقدَّم */
    public function test_merchant_and_courier_chats_live_in_the_row_and_who_wrote_comes_first(): void
    {
        $this->setFeature($this->company, \App\Enums\Feature::Conversations);
        $older = $this->failed();
        $newer = $this->failed(['status_changed_at' => now()->subHour()]);

        $this->actingAs($this->owner)->get($this->host().'/processing')->assertOk()
            ->assertSee('محادثة التاجر')->assertSee('محادثة المندوب')
            ->assertSeeInOrder([$older->number, $newer->number]);

        // المندوب كتب عن الأحدث: تتقدّم، وعليها «ينتظر ردّك»
        Tenancy::runFor($this->company, fn () => app(\App\Actions\Support\CourierChat::class)
            ->send($this->courier, 'الزبون يقول بعد العصر', $this->courierUser(), \App\Actions\Support\CourierChat::COURIER, $newer));
        $this->actingAs($this->owner)->get($this->host().'/processing')->assertOk()
            ->assertSeeInOrder([$newer->number, $older->number])
            ->assertSee('ينتظر ردّك')->assertSee('الزبون يقول بعد العصر');

        // الردّ من الصفّ نفسه: يعود إليه والمحادثة مفتوحة، ولا تبقى «ينتظر ردّك»
        $this->actingAs($this->owner)->from($this->host().'/processing')
            ->post($this->host()."/processing/{$newer->id}/courier-chat", ['body' => 'تمام، أعده بعد العصر'])
            ->assertRedirect($this->host().'/processing#row-'.$newer->id)->assertSessionHas('open_chat', 'courier-'.$newer->id);
        $this->actingAs($this->owner)->get($this->host().'/processing')->assertSeeInOrder([$older->number, $newer->number]);

        // والتاجر: محادثةٌ عن الشحنة تبدأ من الصفّ، وردّه يقدّمها (ظهراً: التاجر يراسل في ساعات الشركة)
        $this->travelTo(now('Asia/Baghdad')->setTime(12, 0));
        $this->actingAs($this->owner)->from($this->host().'/processing')
            ->post($this->host()."/processing/{$older->id}/merchant-chat", ['body' => 'الزبون لا يرد، نعيد التوصيل؟'])
            ->assertSessionHas('open_chat', 'merchant-'.$older->id);
        Tenancy::runFor($this->company, function () use ($older) {
            $conversation = \App\Models\Conversation::where('shipment_id', $older->id)->sole();
            $this->assertTrue((bool) $conversation->merchant_unread);
            $merchantUser = User::create(['name' => 'التاجر', 'phone' => '07790000077', 'password' => 'password',
                'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true]);
            app(\App\Actions\Support\Converse::class)->reply($conversation, 'نعم أعيدوه', $merchantUser, \App\Actions\Support\Converse::MERCHANT);
        });
        $this->actingAs($this->owner)->get($this->host().'/processing')->assertSeeInOrder([$older->number, $newer->number])
            ->assertSee('نعم أعيدوه');
    }

    private function courierUser(): User
    {
        return Tenancy::runFor($this->company, fn () => User::firstOrCreate(['phone' => '07720000099'], [
            'name' => 'مندوب', 'password' => 'password', 'role' => UserRole::Courier, 'courier_id' => $this->courier->id, 'is_active' => true,
        ]));
    }
}
