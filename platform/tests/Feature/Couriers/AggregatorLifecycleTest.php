<?php

namespace Tests\Feature\Couriers;

use App\Actions\Shipments\ProcessFailedAttempt;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\AggregatorEvent;
use App\Models\AggregatorLink;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\FailureReason;
use App\Models\PushDevice;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Aggregator\AggregatorInbox;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * الفحص الشامل لما يصلنا من الوسيط (docs/plan/106): كلّ خيارٍ في ربط الحالات على كلّ حالٍ تكون فيه الشحنة
 * عندنا، فلا يعلق طردٌ «قيد التوصيل» ولا يتعذّر شيءٌ عندنا؛ والردّ عليهم لا ينتظر التطبيق؛ والسبب لكلّ حالة؛
 * وإعادة تطبيق ما تعذّر من السجلّ.
 */
class AggregatorLifecycleTest extends TestCase
{
    use AggregatorFixtures, RefreshDatabase;

    /** لكلّ خيارٍ حالةٌ عندهم بعينها في هذا الفحص */
    private const CODES = [
        'none' => '901', 'track' => '902', 'out_for_delivery' => '903', 'delivered' => '904',
        'failed_attempt' => '905', 'postponed' => '906', 'price_change' => '907', 'return_confirmed' => '908',
        'return_with_courier' => '909', 'redelivery' => '910', 'return_received' => '911',
        'returned_to_merchant' => '912', 'at_hub' => '913', 'exchange_delivered' => '914',
    ];

    private const IT = ShipmentStatus::InTransit;

    private const OFD = ShipmentStatus::OutForDelivery;

    private const FA = ShipmentStatus::FailedAttempt;

    private const PP = ShipmentStatus::Postponed;

    private const RT = ShipmentStatus::Returning;

    private const DL = ShipmentStatus::Delivered;

    /**
     * ما تصير إليه الشحنة، ونتيجته في السجلّ، لكلّ خيارٍ وهي على حالها هذه. «error» لا يكون في أيٍّ منها:
     * ما يحتاج قراراً منّا «ينتظر إجراءً»، وما لا ينطبق سطر تتبّع.
     *
     * @return array<string, array{0: string, 1: array<string, array{0: ShipmentStatus, 1: string}>}>
     */
    public static function lifecycle(): array
    {
        return [
            'بالطريق إليهم' => ['in_transit', [
                'none' => [self::IT, 'recorded'],
                'track' => [self::IT, 'applied'],
                'out_for_delivery' => [self::OFD, 'applied'],
                'delivered' => [self::DL, 'applied'],
                'failed_attempt' => [self::FA, 'applied'],
                'postponed' => [self::PP, 'applied'],
                'price_change' => [self::OFD, 'applied'],
                'return_confirmed' => [self::RT, 'applied'],
                'return_with_courier' => [self::RT, 'applied'],
                'redelivery' => [self::IT, 'applied'],
                'return_received' => [self::RT, 'waiting'],
                'returned_to_merchant' => [self::RT, 'waiting'],
                'at_hub' => [self::IT, 'applied'],
                'exchange_delivered' => [self::IT, 'waiting'],
            ]],
            'قيد التوصيل' => ['out_for_delivery', [
                'none' => [self::OFD, 'recorded'],
                'track' => [self::OFD, 'applied'],
                'out_for_delivery' => [self::OFD, 'applied'],
                'delivered' => [self::DL, 'applied'],
                'failed_attempt' => [self::FA, 'applied'],
                'postponed' => [self::PP, 'applied'],
                'price_change' => [self::OFD, 'applied'],
                'return_confirmed' => [self::RT, 'applied'],
                'return_with_courier' => [self::RT, 'applied'],
                'redelivery' => [self::OFD, 'applied'],
                'return_received' => [self::RT, 'waiting'],
                'returned_to_merchant' => [self::RT, 'waiting'],
                'at_hub' => [self::OFD, 'applied'],
                'exchange_delivered' => [self::OFD, 'waiting'],
            ]],
            'لم يُسلَّم' => ['failed_attempt', [
                'none' => [self::FA, 'recorded'],
                'track' => [self::FA, 'applied'],
                'out_for_delivery' => [self::FA, 'applied'],
                'delivered' => [self::FA, 'waiting'],
                'failed_attempt' => [self::FA, 'applied'],
                'postponed' => [self::FA, 'applied'],
                'price_change' => [self::FA, 'applied'],
                'return_confirmed' => [self::RT, 'applied'],
                'return_with_courier' => [self::RT, 'applied'],
                'redelivery' => [self::IT, 'applied'],
                'return_received' => [self::RT, 'waiting'],
                'returned_to_merchant' => [self::RT, 'waiting'],
                'at_hub' => [self::FA, 'applied'],
                'exchange_delivered' => [self::FA, 'waiting'],
            ]],
            'مؤجّل' => ['postponed', [
                'none' => [self::PP, 'recorded'],
                'track' => [self::PP, 'applied'],
                'out_for_delivery' => [self::PP, 'applied'],
                'delivered' => [self::PP, 'waiting'],
                'failed_attempt' => [self::PP, 'applied'],
                'postponed' => [self::PP, 'applied'],
                'price_change' => [self::PP, 'applied'],
                'return_confirmed' => [self::RT, 'applied'],
                'return_with_courier' => [self::RT, 'applied'],
                'redelivery' => [self::IT, 'applied'],
                'return_received' => [self::RT, 'waiting'],
                'returned_to_merchant' => [self::RT, 'waiting'],
                'at_hub' => [self::PP, 'applied'],
                'exchange_delivered' => [self::PP, 'waiting'],
            ]],
            'راجع مؤكد عندهم' => ['returning', [
                'none' => [self::RT, 'recorded'],
                'track' => [self::RT, 'applied'],
                'out_for_delivery' => [self::RT, 'applied'],
                'delivered' => [self::RT, 'waiting'],
                'failed_attempt' => [self::RT, 'applied'],
                'postponed' => [self::RT, 'applied'],
                'price_change' => [self::RT, 'applied'],
                'return_confirmed' => [self::RT, 'applied'],
                'return_with_courier' => [self::RT, 'applied'],
                'redelivery' => [self::RT, 'applied'],
                'return_received' => [self::RT, 'waiting'],
                'returned_to_merchant' => [self::RT, 'waiting'],
                'at_hub' => [self::RT, 'applied'],
                'exchange_delivered' => [self::RT, 'waiting'],
            ]],
            'واصل' => ['delivered', [
                'none' => [self::DL, 'recorded'],
                'track' => [self::DL, 'applied'],
                'out_for_delivery' => [self::DL, 'applied'],
                'delivered' => [self::DL, 'recorded'],
                'failed_attempt' => [self::DL, 'applied'],
                'postponed' => [self::DL, 'applied'],
                'price_change' => [self::DL, 'applied'],
                'return_confirmed' => [self::DL, 'applied'],
                'return_with_courier' => [self::DL, 'applied'],
                'redelivery' => [self::DL, 'applied'],
                'return_received' => [self::DL, 'applied'],
                'returned_to_merchant' => [self::DL, 'applied'],
                'at_hub' => [self::DL, 'applied'],
                'exchange_delivered' => [self::DL, 'recorded'],
            ]],
        ];
    }

    /** @param  array<string, array{0: ShipmentStatus, 1: string}>  $expected */
    #[DataProvider('lifecycle')]
    public function test_every_status_option_on_every_state_lands_where_it_should_without_errors(string $start, array $expected): void
    {
        $this->assertSame(array_keys(AggregatorLink::ACTIONS), array_keys($expected), 'كلّ خيارٍ في الفحص');

        $this->fakeApi();
        $this->map(array_flip(self::CODES) + ['3' => 'out_for_delivery']);

        // شحنةٌ لكلّ خيار، أُنشئت عندهم دفعةً واحدة، ثم أُوصلت إلى الحال المطلوبة بما يرسلونه هم
        $shipments = [];
        foreach (array_keys($expected) as $action) {
            $shipments[$action] = $this->ship();
        }
        $this->artisan('aggregator:sync')->assertSuccessful();

        foreach ($shipments as $action => $shipment) {
            $shipments[$action] = $this->bring($this->fresh($shipment), $start);
        }

        $checked = [];
        foreach ($expected as $action => [$status, $result]) {
            $shipment = $shipments[$action];

            $this->hook($this->order($shipment, ['status_id' => self::CODES[$action], 'status' => 'حالة '.$action,
                'updated_at' => '2026-11-01 12:00:00']))->assertOk()->assertJson(['status' => true]);

            $event = $this->lastEvent();
            $checked[$action] = [
                $this->fresh($shipment)->status->value,
                $event->result,
            ];

            $this->assertSame((int) $shipment->id, (int) $event->shipment_id, $action);
            $this->assertNotNull($event->applied_at, $action);
            $this->assertNotSame('تعذّر تطبيقه عندنا.', $event->message, $action.': خطأٌ عندنا');
        }

        // كلّها معاً، فيُرى الجدول كلّه حين يختلف شيء
        $this->assertSame(array_map(fn ($e) => [$e[0]->value, $e[1]], $expected), $checked);
        $this->assertSame(0, $this->pendingCount());
    }

    /** يوصلها إلى حالها بما يرسلونه، كما تصل في الحقيقة */
    private function bring(Shipment $shipment, string $start): Shipment
    {
        $send = fn (array $over) => $this->hook($this->order($shipment, $over + ['updated_at' => '2026-11-01 09:00:00']))->assertOk();

        if ($start !== 'in_transit') {
            $send(['status_id' => '3', 'updated_at' => '2026-11-01 08:00:00']);
        }

        match ($start) {
            'failed_attempt' => $send(['status_id' => self::CODES['failed_attempt']]),
            'postponed' => $send(['status_id' => self::CODES['postponed']]),
            'returning' => $send(['status_id' => self::CODES['return_confirmed']]),
            'delivered' => $send(['status_id' => '4', 'deliver_done' => 1]),
            default => null,
        };

        $shipment = $this->fresh($shipment);
        $this->assertSame($start, $shipment->status->value, 'الحال قبل الفحص');

        return $shipment;
    }

    public function test_cancelling_at_their_side_reaches_processing_with_their_status_as_the_reason(): void
    {
        $this->fakeApi();
        $this->map(['40' => 'failed_attempt', '41' => 'postponed']);

        // «الغاء الطلب» ومندوبهم كتب سببه: «لم يُسلَّم» للمعالجة، سببه نصّ حالتهم، وما كتبه ملاحظةٌ للكول سنتر
        $shipment = $this->outWithThem();
        $this->hook($this->order($shipment, ['status_id' => '40', 'status' => 'الغاء الطلب', 'issue_notes' => 'الزبون ما يرد']))->assertOk();

        $after = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::FailedAttempt, $after->status);
        $this->assertSame('الغاء الطلب', $this->reasonName($after));
        $this->assertSame('وسيط الجنوب: الزبون ما يرد', $after->staff_note);
        $this->assertSame('applied', $this->lastEvent()->result);
        $this->assertTrue(Tenancy::runFor($this->company, fn () => Announcement::query()->where('merchant_id', $after->merchant_id)
            ->where('body', 'like', '%الغاء الطلب%')->exists()), 'التاجر يقرأ السبب بكلامهم');

        // لا يظهر في قوائم الأسباب التي يختار منها أحد، ويُقبل حين يُسجَّل
        $names = fn (bool $partner) => Tenancy::runFor($this->company, fn () => FailureReason::availableFor($this->company->id, $partner)->pluck('name_ar')->all());
        $this->assertNotContains('الغاء الطلب', $names(false));
        $this->assertContains('الغاء الطلب', $names(true));

        // شحنةٌ ثانية بالحالة نفسها: السبب نفسه لا نسخةٌ منه، وإن غيّروا نصّها تغيّر اسمه
        $other = $this->outWithThem();
        $this->hook($this->order($other, ['status_id' => '40', 'status' => 'الغاء الطلب من الزبون']))->assertOk();
        $this->assertSame((int) $after->last_failure_reason_id, (int) $this->fresh($other)->last_failure_reason_id);
        $this->assertSame('الغاء الطلب من الزبون', $this->reasonName($other));
        $this->assertNull($this->fresh($other)->staff_note, 'لم يكتب مندوبهم شيئاً');

        // المؤجّل كذلك
        $third = $this->outWithThem();
        $this->hook($this->order($third, ['status_id' => '41', 'status' => 'مؤجل']))->assertOk();
        $this->assertSame(ShipmentStatus::Postponed, $this->fresh($third)->status);
        $this->assertSame('مؤجل', $this->reasonName($third));
    }

    public function test_the_merchant_sees_their_status_as_the_reason_in_processing_and_can_decide(): void
    {
        $this->fakeApi();
        $this->map(['34' => 'failed_attempt']);

        $shipment = $this->outWithThem();
        $this->hook($this->order($shipment, ['status_id' => '34', 'status' => 'طلب مكرر']))->assertOk();
        $this->assertSame(ShipmentStatus::FailedAttempt, $this->fresh($shipment)->status);

        // تاجرٌ فُتحت له المعالجة: يراها في «للمعالجة» بسببها «طلب مكرر»، ويقرّر فيها بنفسه
        $merchant = Tenancy::runFor($this->company, function () use ($shipment) {
            $merchant = $shipment->merchant;
            $merchant->forceFill(['can_process' => true])->save();

            return $merchant;
        });
        $owner = Tenancy::runFor($this->company, fn () => User::create(['name' => 'تاجر', 'phone' => '07790000034',
            'password' => 'password', 'role' => UserRole::Merchant, 'merchant_id' => $merchant->id, 'is_active' => true]));

        $this->actingAs($owner)->get($this->tenantUrl('/portal/processing'))->assertOk()
            ->assertSee($shipment->number)->assertSee('طلب مكرر');

        $this->post($this->tenantUrl('/portal/processing/'.$shipment->id), ['action' => 'redeliver'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        // تعود للوسيط «إعادة توصيل» كما لو قرّرها الكول سنتر، ولا طلب جديد عندهم
        $after = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::InTransit, $after->status);
        $this->assertNotNull($after->redelivery_at);
        $this->assertSame(['create'], $this->jobs()->pluck('action')->all());
    }

    public function test_an_update_that_finds_no_shipment_says_exactly_why_and_replays_once_fixed(): void
    {
        $this->fakeApi();

        // الشحنة عندنا مع مندوبٍ عاديّ لا مع مندوب الربط: لا تُحرَّك، ويقول السجلّ ذلك باسميهما
        $shipment = $this->ship($this->courier('مندوب عادي'));
        $this->hook($this->order($shipment, ['status_id' => '4', 'deliver_done' => 1]))->assertOk();
        $event = $this->lastEvent();
        $this->assertSame('unmatched', $event->result);
        $this->assertStringContainsString('مع «مندوب عادي»', (string) $event->message);
        $this->assertStringContainsString('مندوب الربط «وسيط الجنوب»', (string) $event->message);
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($shipment)->status);

        // رقمٌ لا نعرفه، ورقمٌ بلا بادئتنا
        $this->hook(['id' => '777', 'company_order_id' => 'ZJL_999999', 'status_id' => '3', 'status' => 'مع المندوب'])->assertOk();
        $this->assertStringContainsString('لا شحنة عندنا برقمهم 777 ولا برقمنا ZJL_999999', (string) $this->lastEvent()->message);
        $this->hook(['id' => '778', 'company_order_id' => 'ABC_5', 'status_id' => '3', 'status' => 'مع المندوب'])->assertOk();
        $this->assertStringContainsString('بلا بادئتنا ZJL_', (string) $this->lastEvent()->message);

        // أُسندت لمندوب الربط، ثم «أعد التطبيق»: تُسجَّل واصلة
        Tenancy::runFor($this->company, fn () => $shipment->refresh()->forceFill(['delivery_courier_id' => $this->agent->id])->save());
        $this->actingAs($this->staff)->post($this->tenantUrl('/aggregator/events/'.$event->id.'/replay'))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(ShipmentStatus::Delivered, $this->fresh($shipment)->status);
        $this->assertSame('applied', Tenancy::runFor($this->company, fn () => $event->refresh()->result));
        $this->assertTrue(Tenancy::runFor($this->company, fn () => AuditLog::query()
            ->where('action', 'aggregator_event_replayed')->exists()));
    }

    public function test_replay_is_refused_for_an_applied_update_or_when_a_newer_one_came_after(): void
    {
        $this->fakeApi();
        $shipment = $this->outWithThem();
        $applied = $this->lastEvent();

        $this->actingAs($this->staff)->post($this->tenantUrl('/aggregator/events/'.$applied->id.'/replay'))
            ->assertSessionHasErrors('event');

        // «واصل» بعد «لم يُسلَّم» ينتظر إجراءً منّا
        $this->hook($this->order($shipment, ['status_id' => '23', 'updated_at' => '2026-11-01 10:00:00']))->assertOk();
        $this->hook($this->order($shipment, ['status_id' => '4', 'deliver_done' => 1, 'updated_at' => '2026-11-01 11:00:00']))->assertOk();
        $waiting = $this->lastEvent();
        $this->assertSame('waiting', $waiting->result);

        // وصل بعده تحديثٌ أحدث: الأحدث هو ما عليه الطرد، فلا يُعاد القديم
        $this->hook($this->order($shipment, ['status_id' => '2', 'updated_at' => '2026-11-01 12:00:00']))->assertOk();
        $this->post($this->tenantUrl('/aggregator/events/'.$waiting->id.'/replay'))->assertSessionHasErrors('event');
        $this->assertSame(ShipmentStatus::FailedAttempt, $this->fresh($shipment)->status);
        $this->assertSame('waiting', Tenancy::runFor($this->company, fn () => $waiting->refresh()->result));
    }

    public function test_requests_we_refuse_are_recorded_with_the_reason_and_shown_on_the_screen(): void
    {
        $this->fakeApi();
        $shipment = $this->outWithThem();
        $link = fn () => Tenancy::runFor($this->company, fn () => $this->link->refresh());
        $this->assertNotNull($link()->hook_last_at);
        $this->travel(1)->minutes();

        // مفتاحٌ آخر، وبلا ترويسة: يُرفض ويُكتب لماذا
        $this->hook($this->order($shipment), 'old-key')->assertStatus(401);
        $this->assertStringContainsString('لا يطابق', (string) $link()->hook_rejected_reason);

        $host = 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
        $this->postJson($host.'/api/v1/aggregator/webhook', $this->order($shipment))->assertStatus(401);
        $this->assertStringContainsString('لا ترويسة «X-Hook-Key»', (string) $link()->hook_rejected_reason);

        $this->actingAs($this->staff)->get($this->tenantUrl('/aggregator'))->assertOk()
            ->assertSee('استلام تحديثاتهم')->assertSee('آخر ما أرسلوه رُفض')->assertSee('لا ترويسة «X-Hook-Key»');

        // ثم وصل تحديثٌ سليم: لا تنبيه، والرفض يبقى للعلم
        $this->travel(1)->minutes();
        $this->hook($this->order($shipment, ['status_id' => '2', 'updated_at' => '2026-11-01 09:30:00']))->assertOk();
        $this->get($this->tenantUrl('/aggregator'))->assertOk()->assertDontSee('آخر ما أرسلوه رُفض');

        // ولم يُولَّد مفتاحٌ بعد
        Tenancy::runFor($this->company, fn () => $this->link->forceFill(['webhook_token_hash' => null])->save());
        $this->hook($this->order($shipment))->assertStatus(401);
        $this->assertStringContainsString('لم يُولَّد مفتاح', (string) $link()->hook_rejected_reason);
        $this->get($this->tenantUrl('/aggregator'))->assertSee('لا مفتاح بعد: كلّ تحديثٍ منهم يُرفض');
    }

    public function test_an_update_sent_as_form_fields_or_as_json_text_is_read_like_json(): void
    {
        $this->fakeApi();
        $host = 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/api/v1/aggregator/webhook';

        // حقول نموذج
        $first = $this->outWithThem();
        $this->post($host, $this->order($first, ['status_id' => '4', 'deliver_done' => 1]), ['X-Hook-Key' => self::HOOK])->assertOk();
        $this->assertSame(ShipmentStatus::Delivered, $this->fresh($first)->status);

        // JSON نصّاً في حقل data
        $second = $this->outWithThem();
        $this->post($host, ['data' => json_encode($this->order($second, ['status_id' => '4', 'deliver_done' => 1]))], ['X-Hook-Key' => self::HOOK])->assertOk();
        $this->assertSame(ShipmentStatus::Delivered, $this->fresh($second)->status);
    }

    public function test_their_hook_is_answered_first_and_applied_after_the_reply_or_by_the_scheduler(): void
    {
        $this->fakeApi();
        $shipment = $this->outWithThem();

        // كأنّ الخادم انقطع بعد الردّ: القفل عند غيرنا، فيُحفظ ويُردّ ولا يُطبَّق
        $lock = Cache::lock('aggregator-inbox:'.$this->company->id, 120);
        $this->assertTrue($lock->get());

        $delivered = ['status_id' => '4', 'deliver_done' => 1, 'updated_at' => '2026-11-01 14:00:00'];
        $this->hook($this->order($shipment, $delivered))->assertOk()->assertJson(['status' => true, 'errNum' => 'S000']);
        $this->assertSame('pending', $this->lastEvent()->result);
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($shipment)->status);

        // يصل ثانيةً (أعادوه): لا يُحفظ مرّتين
        $this->hook($this->order($shipment, $delivered))->assertOk();
        $this->assertSame(1, $this->pendingCount());

        // المجدوِل يطبّق ما بقي حين يُفلت القفل
        $lock->release();
        $this->artisan('aggregator:sync')->assertSuccessful();
        $this->assertSame(ShipmentStatus::Delivered, $this->fresh($shipment)->status);
        $this->assertSame('applied', $this->lastEvent()->result);
        $this->assertSame(0, $this->pendingCount());
    }

    public function test_pending_updates_are_applied_in_the_order_they_arrived(): void
    {
        $this->fakeApi();
        $this->map(['40' => 'failed_attempt', '44' => 'redelivery']);
        $shipment = $this->outWithThem();

        $lock = Cache::lock('aggregator-inbox:'.$this->company->id, 120);
        $lock->get();
        $this->hook($this->order($shipment, ['status_id' => '40', 'updated_at' => '2026-11-01 10:00:00']))->assertOk();
        $this->hook($this->order($shipment, ['status_id' => '44', 'updated_at' => '2026-11-01 11:00:00']))->assertOk();
        $this->hook($this->order($shipment, ['status_id' => '3', 'updated_at' => '2026-11-01 12:00:00']))->assertOk();
        $this->hook($this->order($shipment, ['status_id' => '4', 'deliver_done' => 1, 'updated_at' => '2026-11-01 13:00:00']))->assertOk();
        $this->assertSame(4, $this->pendingCount());
        $lock->release();

        $applied = Tenancy::runFor($this->company, fn () => app(AggregatorInbox::class)->processPending($this->link));
        $this->assertSame(4, $applied);

        // لم يُسلَّم ← إعادة توصيل ← قيد التوصيل ← واصل، كما حدث عندهم
        $after = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::Delivered, $after->status);
        $this->assertSame(['applied', 'applied', 'applied', 'applied'],
            Tenancy::runFor($this->company, fn () => AggregatorEvent::query()->orderByDesc('id')->limit(4)->pluck('result')->all()));
    }

    public function test_a_delivery_after_a_failed_attempt_waits_for_processing_then_replay_records_it(): void
    {
        $this->fakeApi();
        $shipment = $this->outWithThem();

        $this->hook($this->order($shipment, ['status_id' => '23', 'updated_at' => '2026-11-01 10:00:00']))->assertOk();
        $this->assertSame(ShipmentStatus::FailedAttempt, $this->fresh($shipment)->status);

        // سلّمها مندوبهم قبل قرار المعالجة: المال لا يتحرّك بقولهم، فتنتظر إجراءً منّا — لا خطأ
        $this->hook($this->order($shipment, ['status_id' => '4', 'deliver_done' => 1, 'updated_at' => '2026-11-01 15:00:00']))->assertOk();
        $delivered = $this->lastEvent();
        $this->assertSame('waiting', $delivered->result);
        $this->assertStringContainsString('أعد التطبيق', (string) $delivered->message);
        $this->assertSame(ShipmentStatus::FailedAttempt, $this->fresh($shipment)->status);

        // الكول سنتر يقرّر إعادة التوصيل، ثم «أعد التطبيق»: تُسجَّل واصلة، ولا طلب جديد عندهم
        Tenancy::runFor($this->company, fn () => app(ProcessFailedAttempt::class)->handle($shipment->refresh(), 'redeliver', $this->staff));
        $this->actingAs($this->staff)->post($this->tenantUrl('/aggregator/events/'.$delivered->id.'/replay'))
            ->assertSessionHasNoErrors();

        $after = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::Delivered, $after->status);
        $this->assertSame('applied', Tenancy::runFor($this->company, fn () => $delivered->refresh()->result));
        $this->assertSame(['create'], $this->jobs()->pluck('action')->all(), 'لا طلب جديد عندهم');
    }

    public function test_the_merchant_phone_is_told_only_after_the_status_is_saved(): void
    {
        // مفتاح Firebase للاختبار ورمز وصولٍ في الكاش، كما في PushNotificationTest
        $key = sys_get_temp_dir().'/fcm-agg-'.uniqid().'.json';
        file_put_contents($key, json_encode(['project_id' => 'p', 'client_email' => 'svc@p.iam', 'private_key' => 'test-only']));
        config(['services.firebase.service_account' => $key]);
        Cache::put('fcm.access_token', 'ya29.test', now()->addMinutes(10));

        $seen = [];
        $this->fakeApi(['fcm.googleapis.com/*' => function () use (&$seen, &$shipment) {
            // لحظة الإرسال: خارج معاملة الحالة، والحالة محفوظةٌ يراها غيرنا
            $seen[] = [DB::transactionLevel(), DB::table('shipments')->where('id', $shipment->id)->value('status')];

            return Http::response(['name' => 'ok']);
        }]);

        $shipment = $this->outWithThem();
        $owner = Tenancy::runFor($this->company, fn () => User::create(['name' => 'تاجر', 'phone' => '07790000009',
            'password' => 'password', 'role' => UserRole::Merchant, 'merchant_id' => $shipment->merchant_id, 'is_active' => true]));
        Tenancy::runFor($this->company, fn () => PushDevice::create(['user_id' => $owner->id, 'token' => 'fcm-merchant', 'platform' => 'android']));

        $this->hook($this->order($shipment, ['status_id' => '4', 'deliver_done' => 1]))->assertOk();
        @unlink($key);

        $this->assertSame(ShipmentStatus::Delivered, $this->fresh($shipment)->status);
        $this->assertTrue(Tenancy::runFor($this->company, fn () => Announcement::query()
            ->where('merchant_id', $shipment->merchant_id)->exists()), '«وصلت شحنتك» في جرس التاجر');
        $this->assertSame([[1, ShipmentStatus::Delivered->value]], $seen, 'أُرسل مرّةً بعد حفظ الحالة');
    }

    public function test_the_screens_have_no_reason_options_and_the_log_offers_replay_with_their_time(): void
    {
        $this->fakeApi();
        Tenancy::runFor($this->company, fn () => $this->link->forceFill(['catalog' => ['statuses' => ['40' => 'الغاء الطلب', '3' => 'مع المندوب']]])->save());

        // ربط الحالات وحده، بلا سببٍ يُختار
        $this->actingAs($this->staff)->put($this->tenantUrl('/aggregator/statuses'), [
            'map' => ['40' => 'failed_attempt', '3' => 'out_for_delivery'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('failed_attempt', Tenancy::runFor($this->company, fn () => $this->link->refresh())->actionFor('40'));

        $this->get($this->tenantUrl('/aggregator'))->assertOk()
            ->assertSee('name="map[40]"', false)
            ->assertDontSee('name="reasons[', false)
            ->assertDontSee('name="failure_reason_id"', false)
            ->assertDontSee('name="postpone_reason_id"', false)
            ->assertSee('نصّ حالتهم نفسه');

        // في السجلّ: وقت وصوله ووقته عندهم، و«أعد التطبيق» لما ينتظر إجراءً
        $shipment = $this->outWithThem();
        $this->hook($this->order($shipment, ['status_id' => '23', 'updated_at' => '2026-11-01 08:05:00']))->assertOk();
        $this->hook($this->order($shipment, ['status_id' => '4', 'deliver_done' => 1, 'updated_at' => '2026-11-01 08:07:09']))->assertOk();

        $this->get($this->tenantUrl('/aggregator/log'))->assertOk()
            ->assertSee('08:07:09')
            ->assertSee(route('aggregator.events.replay', $this->lastEvent()), false)
            ->assertSee('أعد التطبيق');
    }

    private function lastEvent(): AggregatorEvent
    {
        return Tenancy::runFor($this->company, fn () => AggregatorEvent::query()->latest('id')->firstOrFail());
    }

    private function pendingCount(): int
    {
        return Tenancy::runFor($this->company, fn () => AggregatorEvent::query()->where('result', 'pending')->count());
    }

    private function tenantUrl(string $path): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').$path;
    }
}
