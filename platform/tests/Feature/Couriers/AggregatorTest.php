<?php

namespace Tests\Feature\Couriers;

use App\Actions\Returns\ReceiveReturns;
use App\Actions\Shipments\ProcessFailedAttempt;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Models\AggregatorEvent;
use App\Models\AggregatorJob;
use App\Models\AggregatorLink;
use App\Models\AggregatorPlace;
use App\Models\CompanyFeature;
use App\Models\Governorate;
use App\Models\ShipmentEvent;
use App\Models\ShipmentTicket;
use App\Services\Aggregator\AggregatorOutbox;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الربط مع نظام الشركة الوسيطة (docs/plan/104): ما يُسلَّم للوسيط يُنشأ عندهم برقم وصلنا، وما يصلنا
 * منهم يُطبَّق بقواعد المندوب، واستلام الراجع يُبلَّغ لهم — لشركةٍ فُتحت لها الميزة، وفي شركتها وحدها.
 */
class AggregatorTest extends TestCase
{
    use AggregatorFixtures, RefreshDatabase;

    public function test_a_shipment_handed_to_the_linked_intermediary_is_created_there_with_our_number(): void
    {
        $this->fakeApi();
        $shipment = $this->ship();
        $other = $this->ship($this->courier('مندوب عادي'));

        // الوسيط وحده: الشحنة «بالطريق» إليه ولها عملٌ واحد، والمندوب العادي لا
        $this->assertSame(ShipmentStatus::InTransit, $shipment->status);
        $this->assertSame([[$shipment->id, 'create', 'pending']], $this->jobs()->map(fn ($j) => [$j->shipment_id, $j->action, $j->status])->all());

        $this->artisan('aggregator:sync')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/aggre/login') && $this->field($r, 'username') === 'acct');
        Http::assertSent(function (HttpRequest $r) use ($shipment) {
            if (! str_contains($r->url(), '/aggre/create-bulk-orders') || ! str_contains($r->url(), 'token=agg-tok')) {
                return false;
            }
            $order = json_decode($this->field($r, 'data'), true)[0];

            return $order['company_order_id'] === 'ZJL_'.$shipment->number
                && $order['client_mobile'] === '+9647801234567' && $order['client_mobile2'] === '+9647701112233'
                && $order['city_id'] === 5 && $order['region_id'] === 77 && $order['price'] === 40_000
                && $order['merchant_id'] === 55 && $order['package_size'] === 1 && $order['items_number'] === 2
                && $order['metadata'] === (string) $shipment->id && str_contains($order['location'], 'قرب السوق');
        });

        $this->assertSame((string) (9000 + $shipment->id), $this->fresh($shipment)->aggregator_order_id);
        $this->assertNull($this->fresh($other)->aggregator_order_id);
        $this->assertSame('done', $this->jobs()->first()->status);

        // رمز الدخول وكلمة المرور مشفّرتان في القاعدة، ويُستعمل الرمز حتى يقارب انتهاؤه
        $row = DB::table('aggregator_links')->first();
        $this->assertStringNotContainsString('agg-tok', (string) $row->aggre_token);
        $this->assertStringNotContainsString('pass-123', (string) $row->password);

        // لا يُنشأ ثانيةً إن عادت للوسيط «بالطريق» (إعادة توصيل): له رقمٌ عندهم
        Tenancy::runFor($this->company, fn () => AggregatorOutbox::afterTransition($this->fresh($shipment), ShipmentStatus::InTransit));
        $this->assertCount(1, $this->jobs());
        $this->artisan('aggregator:sync')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_an_area_that_is_in_another_of_their_cities_is_sent_with_that_city(): void
    {
        // «العشار» عندنا في البصرة، وعندهم (افتراضاً) منطقةٌ في مدينةٍ أخرى رقمها 9
        Tenancy::runFor($this->company, fn () => AggregatorPlace::query()->where('kind', 'city')
            ->update(['remote_id' => '4413', 'remote_city_id' => '9']));

        $payload = Tenancy::runFor($this->company, fn () => AggregatorOutbox::payload($this->ship(), $this->link));

        $this->assertSame([9, 4413], [$payload['city_id'], $payload['region_id']]);

        // بلا مدينةٍ أخرى: مدينة المحافظة كما كانت
        Tenancy::runFor($this->company, fn () => AggregatorPlace::query()->where('kind', 'city')
            ->update(['remote_id' => '77', 'remote_city_id' => null]));
        $payload = Tenancy::runFor($this->company, fn () => AggregatorOutbox::payload($this->ship(), $this->link));
        $this->assertSame([5, 77], [$payload['city_id'], $payload['region_id']]);
    }

    public function test_an_unmapped_area_fails_with_its_reason_and_is_retried_until_mapped(): void
    {
        $this->fakeApi();
        Tenancy::runFor($this->company, fn () => AggregatorPlace::where('kind', 'city')->delete());
        $shipment = $this->ship();

        $this->artisan('aggregator:sync')->assertSuccessful();

        $job = $this->jobs()->first();
        $this->assertSame('pending', $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertStringContainsString('منطقة «العشار» غير مربوطة', $job->last_error);
        $this->assertTrue($job->next_attempt_at->isFuture());
        Http::assertNothingSent();

        // رُبطت المنطقة وحان موعده: يُسأل عنه أوّلاً (لعلّه أُنشئ ولم يصلنا الردّ)، ثم يُنشأ
        Tenancy::runFor($this->company, function () use ($job) {
            $basra = Governorate::where('code', 'BSR')->firstOrFail();
            AggregatorPlace::create(['kind' => 'city', 'local_id' => $this->area('العشار', $basra), 'remote_id' => '77']);
            $job->forceFill(['next_attempt_at' => now()->subMinute()])->save();
        });
        $this->fakeApi([self::BASE.'/aggre/check-order-exists-by-company-order-number*' => Http::response(['status' => true, 'data' => ['order' => []]])]);

        $this->artisan('aggregator:sync')->assertSuccessful();

        $this->assertSame('done', $this->jobs()->first()->status);
        $this->assertNotNull($this->fresh($shipment)->aggregator_order_id);
    }

    public function test_an_order_they_created_but_we_did_not_hear_about_is_not_created_twice(): void
    {
        Http::fake([
            self::BASE.'/aggre/login' => Http::response(['status' => true, 'data' => ['token' => 'agg-tok']]),
            self::BASE.'/aggre/create-bulk-orders*' => fn () => throw new ConnectionException('timeout'),
        ]);
        $shipment = $this->ship();

        $this->artisan('aggregator:sync')->assertSuccessful();
        $this->assertStringContainsString('لم يردّ', $this->jobs()->first()->last_error);

        Tenancy::runFor($this->company, fn () => AggregatorJob::query()->update(['next_attempt_at' => now()->subMinute()]));
        Http::fake([
            self::BASE.'/aggre/check-order-exists-by-company-order-number*' => Http::response(['status' => true, 'data' => ['order' => [['id' => '777', 'company_order_id' => 'ZJL_'.$shipment->number]]]]),
        ]);

        $this->artisan('aggregator:sync')->assertSuccessful();

        $this->assertSame('777', $this->fresh($shipment)->aggregator_order_id);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'create-bulk-orders'));
    }

    public function test_their_updates_move_the_shipment_by_courier_rules(): void
    {
        $this->fakeApi();
        $shipment = $this->ship();
        $this->artisan('aggregator:sync');
        $shipment = $this->fresh($shipment);

        // مفتاحٌ خاطئ يُرفض ولا يُحفظ
        $this->hook($this->order($shipment, ['status_id' => '3']), 'wrong')->assertStatus(401);

        // مع مندوبهم ← قيد التوصيل
        $this->hook($this->order($shipment, ['status_id' => '3', 'status' => 'مع المندوب']))->assertOk()->assertJsonPath('status', true);
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($shipment)->status);

        // التحديث نفسه ثانيةً لا يُطبَّق مرّتين
        $this->hook($this->order($shipment, ['status_id' => '3', 'status' => 'مع المندوب']))->assertOk();
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => AggregatorEvent::count()));

        // واصل بعلامتهم وبمبلغ الوصل ← واصلة، والمبلغ بيد الوسيط كمندوب
        $this->hook($this->order($shipment, ['status_id' => '4', 'status' => 'تم التسليم', 'deliver_done' => 1]))->assertOk();
        $done = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::Delivered, $done->status);
        $this->assertSame(40_000, (int) $done->collected_amount);
        $this->assertSame(40_000, (int) Tenancy::runFor($this->company, fn () => $this->agent->refresh()->cash_in_hand));
        $this->assertTrue(Tenancy::runFor($this->company, fn () => $done->events()->where('actor_name', 'وسيط الجنوب — ربط آلي')->exists()));
    }

    public function test_a_changed_price_goes_to_the_call_centre_and_never_changes_the_amount_by_itself(): void
    {
        $this->fakeApi();
        $first = $this->ship();
        $second = $this->ship();
        $this->artisan('aggregator:sync');

        // السعر تغيّر وهي مع مندوبهم: تذكرة «السعر تغيّر» والمبلغ كما هو
        $this->hook($this->order($this->fresh($first), ['status_id' => '3', 'price' => '35000', 'old_price' => '40000']))->assertOk();
        $ticket = Tenancy::runFor($this->company, fn () => ShipmentTicket::where('shipment_id', $first->id)->sole());
        $this->assertSame(['price', 35_000, false], [$ticket->kind, (int) $ticket->requested_amount, (bool) $ticket->finalize]);
        $this->assertSame(40_000, (int) $this->fresh($first)->cod_amount);
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($first)->status);

        // سُلّمت بغير مبلغ الوصل: لا تُسجَّل واصلة، تذكرة «سلّمها بسعرٍ آخر» تنتظر الموافقة
        $this->hook($this->order($this->fresh($second), ['status_id' => '4', 'deliver_done' => 1, 'price' => '30000']))->assertOk();
        $ticket = Tenancy::runFor($this->company, fn () => ShipmentTicket::where('shipment_id', $second->id)->sole());
        $this->assertSame([30_000, true], [(int) $ticket->requested_amount, (bool) $ticket->finalize]);
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($second)->status);
    }

    public function test_a_status_mapped_to_price_change_opens_a_ticket_only_when_the_price_differs(): void
    {
        $this->fakeApi();
        Tenancy::runFor($this->company, fn () => $this->link->forceFill(['status_map' => ['9' => 'price_change']])->save());
        $first = $this->ship();
        $second = $this->ship();
        $this->artisan('aggregator:sync');

        // بسعرٍ آخر: تذكرة «السعر تغيّر» للكول سنتر، والمبلغ بحاله، والشحنة خرجت «قيد التوصيل» أوّلاً
        $this->hook($this->order($this->fresh($first), ['status_id' => '9', 'status' => 'تعديل السعر', 'price' => '35000', 'old_price' => '40000']))->assertOk();
        $ticket = Tenancy::runFor($this->company, fn () => ShipmentTicket::where('shipment_id', $first->id)->sole());
        $this->assertSame(['price', 35_000, false], [$ticket->kind, (int) $ticket->requested_amount, (bool) $ticket->finalize]);
        $this->assertSame(40_000, (int) $this->fresh($first)->cod_amount);
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($first)->status);

        // بسعر الوصل نفسه: لا تذكرة، سطر تتبّع فقط
        $this->hook($this->order($this->fresh($second), ['status_id' => '9', 'status' => 'تعديل السعر', 'price' => '40000']))->assertOk();
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => ShipmentTicket::where('shipment_id', $second->id)->count()));
        $this->assertTrue(Tenancy::runFor($this->company, fn () => $second->events()->where('event_type', 'tracking')->where('note', 'like', '%السعر كما هو%')->exists()));

        // وتذكرةٌ مفتوحة لا تتكرّر بتحديثٍ ثانٍ بسعرٍ آخر: يصير سطر تتبّع
        $this->hook($this->order($this->fresh($first), ['status_id' => '9', 'status' => 'تعديل السعر', 'price' => '33000', 'old_price' => '40000']))->assertOk();
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => ShipmentTicket::where('shipment_id', $first->id)->count()));
    }

    public function test_a_return_goes_to_processing_and_receiving_it_is_reported_to_them(): void
    {
        $this->fakeApi();
        $shipment = $this->ship();
        $this->artisan('aggregator:sync');
        $shipment = $this->fresh($shipment);
        $this->hook($this->order($shipment, ['status_id' => '3']))->assertOk();

        // راجعٌ إلى مخزنهم ← لم يُسلَّم بالسبب المختار، وتذهب للمعالجة؛ ومراحله بعدها سطور تتبّع
        $this->hook($this->order($shipment, ['status_id' => '23', 'status' => 'راجع الى مخزن الرواجع', 'issue_notes' => 'الزبون رفض']))->assertOk();
        $failed = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::FailedAttempt, $failed->status);
        $this->assertSame($this->reason($this->company)->id, $failed->last_failure_reason_id);

        $this->hook($this->order($shipment, ['status_id' => '23', 'status' => 'راجع الى مخزن الرواجع', 'issue_notes' => 'الزبون رفض', 'send_to_store' => 2]))->assertOk();
        $this->assertTrue(Tenancy::runFor($this->company, fn () => $shipment->events()->where('event_type', 'tracking')
            ->where('note', 'like', '%وصلت مخزنهم في بغداد%')->exists()));

        // سلّموها بعدُ وهي «لم يُسلَّم» عندنا: لا تُسجَّل بلا قرار، وتنتظر إجراءً منّا في السجلّ (docs/plan/106)
        $this->hook($this->order($shipment, ['status_id' => '4', 'status' => 'تم التسليم', 'deliver_done' => 1]))->assertOk();
        $this->assertSame(ShipmentStatus::FailedAttempt, $this->fresh($shipment)->status);
        $this->assertSame('waiting', Tenancy::runFor($this->company, fn () => AggregatorEvent::latest('id')->first()->result));

        // الكول سنتر يقرّر الإرجاع، ونستلمه من الوسيط ← يُبلَّغ لهم «راجعٌ للتاجر» برقمهم
        Tenancy::runFor($this->company, function () use ($shipment) {
            app(ProcessFailedAttempt::class)->handle($shipment->refresh(), 'return', $this->staff);
            app(ReceiveReturns::class)->handle([$shipment->id], $this->staff);
        });
        $this->assertSame(['create', 'receive_return'], $this->jobs()->pluck('action')->all());

        $this->artisan('aggregator:sync')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/aggre/receive-returned-order')
            && $this->field($r, 'merchant-token') === 'merchant-tok' && $this->field($r, 'order-id') === $shipment->aggregator_order_id);
        $this->assertSame(['done', 'done'], $this->jobs()->pluck('status')->all());
    }

    public function test_every_option_explains_itself_on_the_screen(): void
    {
        $this->assertSame(array_keys(AggregatorLink::ACTIONS), array_keys(AggregatorLink::ACTION_HINTS));
    }

    public function test_a_confirmed_return_goes_through_failed_then_returning_and_stays_with_them_until_scanned(): void
    {
        $this->fakeApi();
        $this->map(['23' => 'return_confirmed', '24' => 'return_with_courier']);
        $shipment = $this->outWithThem();

        $this->hook($this->order($shipment, ['status_id' => '23', 'issue_notes' => 'الزبون رفض']))->assertOk();
        $back = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::Returning, $back->status);
        $this->assertSame('راجع مؤكد', $back->statusLabel());
        $this->assertNotNull($back->return_confirmed_at);
        $this->assertNull($back->return_received_at);
        $this->assertSame($this->reason($this->company)->id, $back->last_failure_reason_id);
        $this->assertSame(1, (int) $back->attempts_count);

        // «راجع عند المندوب» بعدها: راجعةٌ من قبل، فسطر تتبّع لا انتقال
        $this->hook($this->order($shipment, ['status_id' => '24', 'status' => 'راجع عند المندوب']))->assertOk();
        $this->assertSame(ShipmentStatus::Returning, $this->fresh($shipment)->status);

        // «راجع عند المندوب» من «بالطريق» مباشرةً: المسار نفسه حتى «راجع مؤكد»
        $other = $this->ship();
        $this->artisan('aggregator:sync');
        $this->hook($this->order($this->fresh($other), ['status_id' => '24', 'status' => 'راجع عند المندوب']))->assertOk();
        $this->assertSame('راجع مؤكد', $this->fresh($other)->statusLabel());

        // بلا سبب «لم يُسلَّم» مختار: لا تبقى «قيد التوصيل» (docs/plan/106) — تُرجَع بالسبب الاحتياطيّ،
        // ويقول السجلّ ذلك ليُختار سبب حالتهم
        Tenancy::runFor($this->company, fn () => $this->link->forceFill(['failure_reason_id' => null])->save());
        $third = $this->outWithThem();
        $this->hook($this->order($third, ['status_id' => '23']))->assertOk();
        $this->assertSame('راجع مؤكد', $this->fresh($third)->statusLabel());
        $this->assertSame('applied', $this->lastResult());
        $this->assertStringContainsString('الاحتياطيّ', (string) Tenancy::runFor($this->company,
            fn () => AggregatorEvent::latest('id')->first()->message));
    }

    public function test_a_redelivery_sends_a_failed_shipment_back_to_them_as_redelivery(): void
    {
        $this->fakeApi();
        $this->map(['44' => 'redelivery']);
        $shipment = $this->outWithThem();

        // «إعادة توصيل» على شحنةٍ قيد التوصيل لا تعني شيئاً: سطر تتبّع
        $this->hook($this->order($shipment, ['status_id' => '44', 'status' => 'للفرز لإعادة الإرسال', 'updated_at' => '2026-11-01 09:00:00']))->assertOk();
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($shipment)->status);

        // لم يُسلَّم (٢٣)، ثم أعادوها للتوصيل (٤٤): تعود إليهم «بالطريق» وعليها علامة إعادة التوصيل
        $this->hook($this->order($shipment, ['status_id' => '23']))->assertOk();
        $this->assertSame(ShipmentStatus::FailedAttempt, $this->fresh($shipment)->status);
        $this->hook($this->order($shipment, ['status_id' => '44', 'status' => 'للفرز لإعادة الإرسال', 'updated_at' => '2026-11-01 10:00:00']))->assertOk();
        $again = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::InTransit, $again->status);
        $this->assertNotNull($again->redelivery_at);
        $this->assertSame((int) $this->agent->id, (int) $again->delivery_courier_id);

        // خرجت مع مندوبهم ثانيةً: «إعادة توصيل»، ولا طلب جديد عندهم
        $this->hook($this->order($shipment, ['status_id' => '3', 'updated_at' => '2026-11-01 11:00:00']))->assertOk();
        $this->assertSame('إعادة توصيل', $this->fresh($shipment)->statusLabel());
        $this->assertSame(['create'], $this->jobs()->pluck('action')->all());
    }

    public function test_returned_to_us_waits_for_our_scan_and_is_not_reported_back_to_them(): void
    {
        $this->fakeApi();
        $shipment = $this->outWithThem();

        // حالتهم ١٥ «راجع للتاجر» (افتراضاً «راجع بالمخزن»): تصير راجعة، ولا تُحسب في مخزننا قبل المسح
        $this->hook($this->order($shipment, ['status_id' => '15', 'status' => 'راجع للتاجر']))->assertOk();
        $back = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::Returning, $back->status);
        $this->assertNull($back->return_received_at);
        $this->assertSame('waiting', $this->lastResult());

        // مسحناها: استُلمت، ولا يُعاد إبلاغهم وقد قالوه هم
        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)->handle([$shipment->id], $this->staff));
        $this->assertNotNull($this->fresh($shipment)->return_received_at);
        $this->assertSame(['create'], $this->jobs()->pluck('action')->all());

        // وإن أعادوا القول بعد المسح: سُجّل فقط
        $this->hook($this->order($shipment, ['status_id' => '15', 'status' => 'راجع للتاجر', 'updated_at' => '2026-11-01 12:00:00']))->assertOk();
        $this->assertSame('recorded', $this->lastResult());
    }

    public function test_returned_to_merchant_needs_our_scan_first_then_closes_the_return(): void
    {
        $this->fakeApi();
        $this->map(['15' => 'returned_to_merchant']);
        $shipment = $this->outWithThem();

        // لم نستلمها بالمسح: لا تصل تاجرها، تصير راجعةً وتنتظر
        $this->hook($this->order($shipment, ['status_id' => '15', 'status' => 'راجع للتاجر']))->assertOk();
        $this->assertSame(ShipmentStatus::Returning, $this->fresh($shipment)->status);
        $this->assertSame('waiting', $this->lastResult());

        // مسحناها ثم قالوها: «راجع للتاجر»
        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)->handle([$shipment->id], $this->staff));
        $this->hook($this->order($shipment, ['status_id' => '15', 'status' => 'راجع للتاجر', 'updated_at' => '2026-11-01 12:00:00']))->assertOk();
        $this->assertSame(ShipmentStatus::Returned, $this->fresh($shipment)->status);
        $this->assertSame('applied', $this->lastResult());
    }

    public function test_in_their_warehouse_is_a_line_for_the_merchant_not_our_shelf(): void
    {
        $this->fakeApi();
        $this->map(['13' => 'at_hub']);
        $shipment = $this->ship();
        $this->artisan('aggregator:sync');

        $this->hook($this->order($this->fresh($shipment), ['status_id' => '13', 'status' => 'في مخزن بغداد']))->assertOk();

        // حالتها عندنا كما هي: «بالطريق» إلى الوسيط، لا «بالمخزن» على رفوفنا
        $this->assertSame(ShipmentStatus::InTransit, $this->fresh($shipment)->status);
        $line = Tenancy::runFor($this->company, fn () => $shipment->events()->where('event_type', 'tracking')->latest('id')->first());
        $this->assertStringContainsString('بالمخزن عند شريك التوصيل', $line->note);
        $this->assertContains('tracking', ShipmentEvent::MERCHANT_EVENTS);
    }

    public function test_delivering_and_taking_back_an_exchange_becomes_a_partial_delivery_with_the_old_piece_returning(): void
    {
        $this->fakeApi();
        $this->map(['30' => 'exchange_delivered']);
        $shipment = $this->ship(null, ['type' => 'exchange']);
        $this->artisan('aggregator:sync');
        $shipment = $this->fresh($shipment);
        $this->hook($this->order($shipment, ['status_id' => '3']))->assertOk();

        // سلّموا الجديد واستلموا القديم: «واصل جزئي»، والمبلغ على الوسيط، والرسالة بحالتها الفعلية
        $this->hook($this->order($shipment, ['status_id' => '30', 'status' => 'تم التسليم واستلام الاسترجاع']))->assertOk();
        $done = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::PartiallyDelivered, $done->status);
        $this->assertSame(40_000, (int) $done->collected_amount);
        $this->assertSame(40_000, (int) Tenancy::runFor($this->company, fn () => $this->agent->refresh()->cash_in_hand));
        $this->assertSame('applied', $this->lastResult());
        $this->assertSame('واصل جزئي', Tenancy::runFor($this->company, fn () => AggregatorEvent::latest('id')->first()->message));

        // القديمة تعود مع الراجع: نستلمها بالمسح فتصير راجعة، ويُبلَّغون باستلامها
        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)->handle([$shipment->id], $this->staff));
        $back = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::Returning, $back->status);
        $this->assertNotNull($back->return_received_at);
        $this->assertSame(['create', 'receive_return'], $this->jobs()->pluck('action')->all());
    }

    public function test_an_exchange_status_on_an_ordinary_shipment_changes_nothing_and_waits_for_a_person(): void
    {
        $this->fakeApi();
        $this->map(['30' => 'exchange_delivered']);
        $shipment = $this->outWithThem();

        $this->hook($this->order($shipment, ['status_id' => '30', 'status' => 'تم التسليم واستلام الاسترجاع', 'updated_at' => '2026-11-01 13:00:00']))->assertOk();

        $same = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::OutForDelivery, $same->status);
        $this->assertNull($same->delivered_at);
        $this->assertNotSame('exchange', $same->type);
        $this->assertSame('waiting', $this->lastResult());
        $this->assertTrue(Tenancy::runFor($this->company, fn () => $shipment->events()->where('event_type', 'tracking')->where('note', 'like', '%استبدال%')->exists()));

        // وعلامة التسليم العادية على شحنة استبدال تصير «واصل جزئي» بقاعدة الاستبدال نفسها
        $exchange = $this->ship(null, ['type' => 'exchange']);
        $this->artisan('aggregator:sync');
        $this->hook($this->order($this->fresh($exchange), ['status_id' => '3']))->assertOk();
        $this->hook($this->order($this->fresh($exchange), ['status_id' => '4', 'status' => 'تم التسليم', 'deliver_done' => 1, 'updated_at' => '2026-11-01 14:00:00']))->assertOk();
        $this->assertSame(ShipmentStatus::PartiallyDelivered, $this->fresh($exchange)->status);
    }

    public function test_their_system_can_move_status_but_never_touch_money_address_or_deletion(): void
    {
        $this->fakeApi();
        $shipment = $this->ship();
        $theirs = $this->ship($this->courier('مندوب عادي'));
        $this->artisan('aggregator:sync');
        $shipment = $this->fresh($shipment);

        $protected = ['cod_amount', 'delivery_fee', 'return_fee', 'extra_fee', 'cod_fee', 'discount', 'total_fees', 'merchant_due', 'courier_commission',
            'recipient_name', 'recipient_phone', 'recipient_phone_alt', 'address', 'landmark', 'governorate_id', 'city_id', 'description', 'notes',
            'merchant_id', 'delivery_courier_id', 'pieces_count', 'weight_grams', 'deleted_at', 'cancelled_at'];
        $before = collect($protected)->mapWithKeys(fn ($k) => [$k => $shipment->getRawOriginal($k)])->all();
        $otherStatus = $this->fresh($theirs)->status;
        $other = collect($protected)->mapWithKeys(fn ($k) => [$k => $this->fresh($theirs)->getRawOriginal($k)])->all();

        // كل ما يمكن أن يُرسَل: سعرٌ وأجرةٌ وعنوانٌ واسمٌ وحذفٌ وإلغاء، وحقولٌ لا نعرفها
        $hostile = $this->order($shipment, [
            'status_id' => '3', 'price' => '1', 'delivery_price' => '1', 'cod_amount' => 1, 'delivery_fee' => 1,
            'client_name' => 'مخترق', 'client_mobile' => '+9647000000000', 'client_mobile2' => '+9647000000001',
            'location' => 'مكان آخر', 'city_id' => '1', 'region_id' => '2', 'city_name' => 'غير', 'region_name' => 'غير',
            'type_name' => 'x', 'items_number' => '99', 'merchant_notes' => 'x', 'package_size' => 'x',
            'deleted' => 1, 'is_deleted' => 1, 'deleted_at' => '2026-11-01 00:00:00', 'cancelled' => 1, 'status' => 'محذوف', 'delete' => true,
        ]);
        $this->hook($hostile)->assertOk();

        // الحالة تتغيّر بقاعدة المندوب، وكل حقلٍ حسّاس كما كان
        $after = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::OutForDelivery, $after->status);
        foreach ($protected as $key) {
            $this->assertSame($before[$key], $after->getRawOriginal($key), $key);
        }

        // تغيّر السعر الحقيقي (سعرٌ قديم) لا يغيّر المبلغ: تذكرةٌ فقط
        $this->hook($this->order($this->fresh($shipment), ['status_id' => '3', 'price' => '5000', 'old_price' => '40000', 'updated_at' => '2026-11-01 15:00:00']))->assertOk();
        $this->assertSame(40_000, (int) $this->fresh($shipment)->cod_amount);
        $this->assertNull($this->fresh($shipment)->deleted_at);

        // شحنةٌ ليست عند الوسيط لا يبلغها ولو عرف رقمها
        $this->hook($this->order($this->fresh($theirs), ['status_id' => '3', 'updated_at' => '2026-11-01 16:00:00']))->assertOk();
        $this->assertSame('unmatched', $this->lastResult());
        $untouched = $this->fresh($theirs);
        $this->assertSame($otherStatus, $untouched->status);
        foreach ($protected as $key) {
            $this->assertSame($other[$key], $untouched->getRawOriginal($key), $key);
        }

        // وليس للمسار غير POST: لا حذف ولا تعديل ولا قراءة
        $url = 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/api/v1/aggregator/webhook';
        $key = ['X-Hook-Key' => self::HOOK];
        $this->getJson($url, $key)->assertStatus(405);
        foreach (['delete', 'put', 'patch'] as $verb) {
            $this->{$verb.'Json'}($url, [], $key)->assertStatus(405);
        }
    }

    public function test_nothing_happens_without_the_feature_and_no_company_reaches_another(): void
    {
        $this->fakeApi();
        $shipment = $this->ship();
        $this->artisan('aggregator:sync');
        $shipment = $this->fresh($shipment);

        // شركةٌ أخرى مربوطةٌ بمفتاحها: مفتاحها لا يفتح نطاقنا، ونطاقها لا يرى شحنتنا
        $other = $this->makeCompany('other', 'شركة أخرى');
        $this->setFeature($other, Feature::Aggregator);
        $this->link($other, $this->courier('وسيط آخر', true, $other), 'other-hook');

        $this->hook($this->order($shipment, ['status_id' => '3']), 'other-hook')->assertStatus(401);
        $this->hook($this->order($shipment, ['status_id' => '3']), 'other-hook', $other)->assertOk();
        $this->assertSame(ShipmentStatus::InTransit, $this->fresh($shipment)->status);
        $this->assertSame('unmatched', Tenancy::runFor($other, fn () => AggregatorEvent::sole()->result));

        // أُغلقت الميزة: لا عمل يُكتب، ولا تحديث يُقبل
        Tenancy::runFor($this->company, fn () => CompanyFeature::where('feature', Feature::Aggregator->value)->update(['enabled' => false]));
        $this->company->forgetFeatures();
        $this->ship();
        $this->assertCount(1, $this->jobs());
        $this->hook($this->order($shipment, ['status_id' => '3']))->assertNotFound();
    }
}
