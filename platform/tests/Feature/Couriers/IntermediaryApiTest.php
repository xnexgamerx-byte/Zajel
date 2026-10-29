<?php

namespace Tests\Feature\Couriers;

use App\Actions\Returns\ReceiveReturns;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\ProcessFailedAttempt;
use App\Actions\Shipments\ReceiveAtHub;
use App\Actions\Shipments\SendOutForDelivery;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierApiKey;
use App\Models\FailureReason;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\MerchantMessage;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * الشركة الوسيطة مندوبٌ عندنا (docs/plan/64): نسلّمها الشحنات كأيّ مندوب، ونظامها يسجّل ما يسجّله
 * المندوب بشروطه نفسها، والمال على حسابها كمندوب يُسوّى بكشف المندوب.
 */
class IntermediaryApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $staff;

    private Courier $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->staff = $this->makeUser($this->company);
        $this->agent = $this->courier('وسيط الجنوب', intermediary: true);
    }

    private function courier(string $name, bool $intermediary = false): Courier
    {
        return Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C'.random_int(100, 999), 'name' => $name, 'phone' => '0772'.random_int(1_000_000, 9_999_999),
            'type' => 'delivery', 'status' => 'active', 'is_intermediary' => $intermediary,
            'commission_per_delivery' => 6_000, 'commission_per_return' => 2_000,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function url(string $path): string
    {
        return $this->host().'/api/v1/partner'.$path;
    }

    private function key(?Courier $courier = null, array $abilities = [CourierApiKey::READ, CourierApiKey::STATUS], ?array $ips = null, ?\DateTimeInterface $expires = null): string
    {
        return Tenancy::runFor($this->company, fn () => CourierApiKey::issue(
            $courier ?? $this->agent, 'نظامهم', $abilities, $expires ? now()->setTimestamp($expires->getTimestamp()) : now()->addMonths(6), $ips, $this->staff,
        ))['token'];
    }

    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /** شحنةٌ للبصرة أُسندت لمندوب (الوسيط افتراضاً) كما يُسند أيّ مندوب */
    private function ship(string $name, ?Courier $courier = null, array $extra = [], bool $push = true): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($name, $courier, $extra, $push) {
            $shipment = app(CreateShipment::class)->handle(array_merge([
                'merchant_id' => $this->makeMerchant($this->company, 'M'.random_int(1000, 9999))->id,
                'recipient_name' => $name, 'recipient_phone' => '07801234567',
                'governorate_id' => Governorate::where('code', 'BSR')->firstOrFail()->id,
                'address' => 'البصرة، العشار', 'cod_amount' => 40_000,
            ], $extra), $this->staff);

            $courier ??= $this->agent;
            app(SendOutForDelivery::class)->handle(collect([$shipment->refresh()]), $courier, $this->staff);

            // الوسيط يخرج الطرد من نظامه بعد أن يصله «بالطريق»، كما يحدث عنده
            if ($push && $courier->is_intermediary) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, null, [
                    'actor_type' => 'courier', 'courier_id' => $courier->id,
                ]);
            }

            return $shipment->refresh();
        });
    }

    private function fresh(Shipment $shipment): Shipment
    {
        return Tenancy::runFor($this->company, fn () => $shipment->refresh());
    }

    private function reason(): FailureReason
    {
        return FailureReason::availableFor($this->company->id)->where('requires_note', false)->firstOrFail();
    }

    public function test_the_intermediary_sees_only_what_is_assigned_to_it_and_no_merchant_or_fees(): void
    {
        $token = $this->key();
        $mine = $this->ship('علي');
        $other = $this->ship('زينب', $this->courier('مندوب بغداد'));

        $this->getJson($this->url('/me'), $this->auth($token))->assertOk()
            ->assertJsonPath('courier.name', 'وسيط الجنوب')
            ->assertJsonPath('held.total', 1)
            ->assertJsonPath('held.to_deliver', 1);

        $row = $this->getJson($this->url('/shipments'), $this->auth($token))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $mine->number)
            ->assertJsonPath('data.0.status', 'out_for_delivery')
            ->assertJsonPath('data.0.amount_to_collect', 40_000)
            ->assertJsonPath('data.0.recipient.name', 'علي')
            ->assertJsonPath('data.0.actions', ['delivered', 'failed_attempt', 'postponed'])
            ->json('data.0');

        foreach (['merchant', 'merchant_id', 'delivery_fee', 'total_fees', 'merchant_due', 'cod_fee', 'courier_commission', 'delivery_code', 'id'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $row);
        }

        // شحنة مندوبٍ آخر: الجواب نفسه لغير الموجود، لا قراءة ولا تسجيل
        $this->getJson($this->url('/shipments/'.$other->number), $this->auth($token))->assertNotFound();
        $this->postJson($this->url('/shipments/'.$other->number.'/status'), ['action' => 'delivered'], $this->auth($token))->assertNotFound();
        $this->postJson($this->url('/shipments/'.$other->number.'/track'), ['note' => 'x'], $this->auth($token))->assertNotFound();
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($other)->status);
    }

    public function test_delivered_from_their_system_is_cash_on_the_intermediary_settled_like_any_courier(): void
    {
        $token = $this->key();
        $shipment = $this->ship('علي');

        $this->postJson($this->url('/shipments/'.$shipment->number.'/status'), ['action' => 'delivered'], $this->auth($token))
            ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.actions', []);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $shipment->refresh();
            $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
            $this->assertSame(40_000, (int) $shipment->collected_amount);
            $this->assertSame(6_000, (int) $shipment->courier_commission);

            $agent = $this->agent->refresh();
            $this->assertSame(40_000, (int) $agent->cash_in_hand);
            $this->assertSame(34_000, $agent->netDue());

            $event = ShipmentEvent::where('shipment_id', $shipment->id)->where('to_status', 'delivered')->firstOrFail();
            $this->assertSame('courier', $event->actor_type);
            $this->assertStringContainsString('ربط آلي', $event->actor_name);
            $this->assertSame($this->agent->id, $event->courier_id);

            // كشف المندوب نفسه يحاسبه: ما قبضه ناقص أجرته
            $settlement = app(BuildCourierSettlement::class)->handle($agent, $this->staff);
            $this->assertSame(1, $settlement->shipments_count ?? $settlement->lines()->count());
        });

        // ولا يُسجَّل مرّتين
        $this->postJson($this->url('/shipments/'.$shipment->number.'/status'), ['action' => 'delivered'], $this->auth($token))
            ->assertUnprocessable()->assertJsonValidationErrors('action');
    }

    public function test_the_amount_and_anything_beyond_the_courier_fields_is_refused(): void
    {
        $token = $this->key();
        $shipment = $this->ship('علي');

        foreach ([['collected_amount' => 1_000], ['cod_amount' => 1_000], ['status' => 'returning'], ['address' => 'غيره']] as $extra) {
            $this->postJson($this->url('/shipments/'.$shipment->number.'/status'), ['action' => 'delivered'] + $extra, $this->auth($token))
                ->assertUnprocessable()->assertJsonValidationErrors('fields');
        }

        foreach (['returning', 'returned', 'cancelled', 'partially_delivered', 'at_hub'] as $action) {
            $this->postJson($this->url('/shipments/'.$shipment->number.'/status'), ['action' => $action], $this->auth($token))
                ->assertUnprocessable()->assertJsonValidationErrors('action');
        }

        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($shipment)->status);
        $this->assertSame(0, (int) Tenancy::runFor($this->company, fn () => $this->agent->refresh()->cash_in_hand));
    }

    public function test_not_delivered_needs_our_reason_and_then_waits_for_our_decision_then_returns_to_us(): void
    {
        $token = $this->key();
        $shipment = $this->ship('علي');
        $url = $this->url('/shipments/'.$shipment->number.'/status');

        $this->postJson($url, ['action' => 'failed_attempt'], $this->auth($token))
            ->assertUnprocessable()->assertJsonValidationErrors('reason_id');
        $this->postJson($url, ['action' => 'failed_attempt', 'reason_id' => 999_999], $this->auth($token))
            ->assertUnprocessable()->assertJsonValidationErrors('reason_id');

        $reasons = $this->getJson($this->url('/reasons'), $this->auth($token))->assertOk()->json('data');
        $this->assertNotEmpty($reasons);

        $reason = $this->reason();
        $this->postJson($url, ['action' => 'failed_attempt', 'reason_id' => $reason->id, 'note' => 'الهاتف مغلق'], $this->auth($token))
            ->assertOk()->assertJsonPath('data.status', 'failed_attempt')->assertJsonPath('data.actions', []);

        $this->assertSame($reason->id, (int) $this->fresh($shipment)->last_failure_reason_id);
        $this->getJson($this->url('/me'), $this->auth($token))->assertJsonPath('held.awaiting_our_decision', 1);

        // القرار لنا: الكول سنتر يقرّر الإرجاع، فيظهر عندهم «أرجعوه إلينا» حتى نستلمه
        $this->postJson($url, ['action' => 'postponed', 'reason_id' => $reason->id], $this->auth($token))->assertUnprocessable();
        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::Returning, $this->staff));

        $this->getJson($this->url('/shipments/'.$shipment->number), $this->auth($token))->assertOk()
            ->assertJsonPath('data.return_to_us', true)->assertJsonPath('data.amount_to_collect', 0);

        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)->handle([$shipment->id], $this->staff));
        $this->getJson($this->url('/shipments/'.$shipment->number), $this->auth($token))->assertNotFound();
        $this->getJson($this->url('/me'), $this->auth($token))->assertJsonPath('held.total', 0);
    }

    /** لا كود تسليم (docs/plan/76): ولو حمل وصلٌ قديم كوداً، يُسلَّم بلا إجراءٍ آخر */
    public function test_delivered_needs_no_delivery_code(): void
    {
        $token = $this->key();
        $shipment = $this->ship('علي');
        Tenancy::runFor($this->company, fn () => $shipment->forceFill(['delivery_code' => '4821'])->save());

        $this->getJson($this->url('/shipments/'.$shipment->number), $this->auth($token))
            ->assertJsonPath('data.needs_delivery_code', false)->assertJsonMissingPath('data.delivery_code');
        $this->postJson($this->url('/shipments/'.$shipment->number.'/status'), ['action' => 'delivered'], $this->auth($token))
            ->assertOk();
        $this->assertSame(ShipmentStatus::Delivered, $this->fresh($shipment)->status);
    }

    public function test_tracking_notes_show_in_the_merchants_timeline_without_changing_the_status(): void
    {
        $token = $this->key();
        $shipment = $this->ship('علي');

        $this->postJson($this->url('/shipments/'.$shipment->number.'/track'), ['note' => 'وصلت فرع البصرة'], $this->auth($token))
            ->assertCreated();
        $this->postJson($this->url('/shipments/'.$shipment->number.'/track'), ['note' => 'x', 'status' => 'delivered'], $this->auth($token))
            ->assertUnprocessable()->assertJsonValidationErrors('fields');
        $this->postJson($this->url('/shipments/'.$shipment->number.'/track'), [], $this->auth($token))
            ->assertUnprocessable()->assertJsonValidationErrors('note');

        Tenancy::runFor($this->company, function () use ($shipment) {
            $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->refresh()->status);
            $event = ShipmentEvent::where('shipment_id', $shipment->id)->where('event_type', 'tracking')->sole();
            $this->assertSame('وصلت فرع البصرة', $event->merchantHeadline());
            $this->assertContains('tracking', ShipmentEvent::MERCHANT_EVENTS);
        });
    }

    public function test_keys_are_hashed_and_dead_keys_wrong_couriers_and_wrong_ips_are_refused(): void
    {
        $token = $this->key();
        $key = Tenancy::runFor($this->company, fn () => CourierApiKey::query()->latest('id')->firstOrFail());
        $this->assertSame(CourierApiKey::hash($token), $key->token_hash);
        $this->assertStringNotContainsString($token, json_encode($key->toArray()));

        $this->getJson($this->url('/me'))->assertUnauthorized();
        $this->getJson($this->url('/me'), $this->auth('zjp_'.str_repeat('a', 40)))->assertUnauthorized();
        $this->getJson($this->url('/me'), $this->auth($token))->assertOk();

        // مندوبٌ لم يعد وسيطاً، أو أُوقف، يُرفض مفتاحه فوراً
        Tenancy::runFor($this->company, fn () => $this->agent->forceFill(['is_intermediary' => false])->save());
        $this->getJson($this->url('/me'), $this->auth($token))->assertUnauthorized();
        Tenancy::runFor($this->company, fn () => $this->agent->forceFill(['is_intermediary' => true, 'status' => 'suspended'])->save());
        $this->getJson($this->url('/me'), $this->auth($token))->assertUnauthorized();
        Tenancy::runFor($this->company, fn () => $this->agent->forceFill(['status' => 'active'])->save());

        Tenancy::runFor($this->company, fn () => $key->revoke());
        $this->getJson($this->url('/me'), $this->auth($token))->assertUnauthorized();

        $expired = $this->key(expires: now()->subMinute());
        $this->getJson($this->url('/me'), $this->auth($expired))->assertUnauthorized();

        $fenced = $this->key(ips: ['203.0.113.10']);
        $this->getJson($this->url('/me'), $this->auth($fenced))->assertForbidden();
        $this->getJson($this->url('/me'), $this->auth($fenced) + ['REMOTE_ADDR' => '203.0.113.10'])->assertOk();
    }

    public function test_a_key_from_another_company_does_not_work_here(): void
    {
        $other = $this->makeCompany('barq', 'البرق');
        $otherStaff = $this->makeUser($other);
        $foreign = Tenancy::runFor($other, function () use ($otherStaff) {
            $courier = Courier::create(['code' => 'C9', 'name' => 'وسيط', 'phone' => '07729999999', 'type' => 'delivery', 'status' => 'active', 'is_intermediary' => true]);

            return CourierApiKey::issue($courier, 'k', array_keys(CourierApiKey::ABILITIES), now()->addMonth(), null, $otherStaff)['token'];
        });

        $this->getJson($this->url('/me'), $this->auth($foreign))->assertUnauthorized();
        $this->getJson('http://barq.'.config('zajel.tenant_domain').'/api/v1/partner/me', $this->auth($foreign))->assertOk();
    }

    public function test_a_read_only_key_cannot_record_anything(): void
    {
        $token = $this->key(abilities: [CourierApiKey::READ]);
        $shipment = $this->ship('علي');

        $this->getJson($this->url('/shipments'), $this->auth($token))->assertOk();
        $this->postJson($this->url('/shipments/'.$shipment->number.'/status'), ['action' => 'delivered'], $this->auth($token))->assertForbidden();
        $this->postJson($this->url('/shipments/'.$shipment->number.'/track'), ['note' => 'x'], $this->auth($token))->assertForbidden();
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($shipment)->status);
    }

    public function test_guessing_keys_is_throttled_per_address(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->getJson($this->url('/me'), $this->auth('zjp_guess'.$i))->assertUnauthorized();
        }

        $this->getJson($this->url('/me'), $this->auth('zjp_guess'))->assertStatus(429);
        // حتى المفتاح الصحيح من العنوان نفسه ينتظر الدقيقة
        $this->getJson($this->url('/me'), $this->auth($this->key()))->assertStatus(429);
    }

    public function test_the_owner_issues_a_key_on_the_intermediary_page_and_sees_it_once(): void
    {
        $page = $this->host().'/couriers/'.$this->agent->id;

        $this->actingAs($this->staff)->get($page)->assertOk()->assertSee('ربط نظام الشركة الوسيطة');

        $this->actingAs($this->staff)->post($page.'/api-keys', [
            'name' => 'نظامهم الرئيسي',
            'abilities' => ['shipments.read', 'shipments.status', 'money.pay'],
            'expires_at' => now()->addMonths(3)->toDateString(),
        ])->assertSessionHasErrors('abilities.2');

        $response = $this->actingAs($this->staff)->post($page.'/api-keys', [
            'name' => 'نظامهم الرئيسي',
            'abilities' => ['shipments.read', 'shipments.status'],
            'expires_at' => now()->addMonths(3)->toDateString(),
            'allowed_ips' => '203.0.113.10, 203.0.113.11',
        ])->assertRedirect($page)->assertSessionHas('api_token');

        $token = (string) session('api_token');
        $this->assertStringStartsWith('zjp_', $token);

        $this->actingAs($this->staff)->get($page)->assertOk()->assertSee($token)->assertSee('نظامهم الرئيسي');
        $this->actingAs($this->staff)->get($page)->assertOk()->assertDontSee($token)->assertSee('نظامهم الرئيسي');

        $key = Tenancy::runFor($this->company, fn () => CourierApiKey::query()->latest('id')->firstOrFail());
        $this->assertSame(['203.0.113.10', '203.0.113.11'], $key->allowed_ips);

        // مفتاح مندوبٍ آخر لا يُلغى من صفحة هذا، والإلغاء من صفحته يقفله
        $plain = $this->courier('مندوب بغداد');
        $this->actingAs($this->staff)->post($this->host().'/couriers/'.$plain->id.'/api-keys/'.$key->id.'/revoke')->assertNotFound();
        $this->actingAs($this->staff)->post($page.'/api-keys/'.$key->id.'/revoke')->assertRedirect($page);
        $this->assertNotNull(Tenancy::runFor($this->company, fn () => $key->refresh()->revoked_at));

        // مندوبٌ عادي لا مفاتيح له
        $this->actingAs($this->staff)->post($this->host().'/couriers/'.$plain->id.'/api-keys', [
            'name' => 'x', 'abilities' => ['shipments.read'], 'expires_at' => now()->addMonth()->toDateString(),
        ])->assertNotFound();
    }

    public function test_only_the_company_settings_holder_issues_keys(): void
    {
        $ops = $this->makeUser($this->company, UserRole::Operations);

        $this->actingAs($ops)->post($this->host().'/couriers/'.$this->agent->id.'/api-keys', [
            'name' => 'x', 'abilities' => ['shipments.read'], 'expires_at' => now()->addMonth()->toDateString(),
        ])->assertForbidden();
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => CourierApiKey::count()));
    }

    public function test_the_courier_form_marks_an_intermediary(): void
    {
        $plain = $this->courier('شركة الشمال');
        $form = $this->actingAs($this->staff)->get($this->host().'/couriers/'.$plain->id.'/edit')->assertOk()->assertSee('شركة وسيطة');
        $this->assertNotNull($form);

        Tenancy::runFor($this->company, fn () => $plain->forceFill(['is_intermediary' => true])->save());
        $this->assertTrue(Tenancy::runFor($this->company, fn () => $plain->refresh()->is_intermediary));
    }

    public function test_the_merchant_never_sees_the_intermediary_name_but_sees_a_normal_courier(): void
    {
        $merchant = $this->makeMerchant($this->company, 'MX1');
        // تاجرٌ فُتح له المندوب (docs/plan/95): الوسيطة تبقى «شريك التوصيل»
        Tenancy::runFor($this->company, fn () => $merchant->update(['hides_courier' => false]));
        $merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => $this->phoneFrom($this->company->slug.'merchant-x', '0779'),
            'password' => 'password', 'role' => UserRole::Merchant, 'merchant_id' => $merchant->id, 'is_active' => true,
        ]));
        $normal = $this->courier('مندوب بغداد');

        $viaAgent = $this->ship('علي', null, ['merchant_id' => $merchant->id]);
        $viaNormal = $this->ship('زينب', $normal, ['merchant_id' => $merchant->id]);

        $headers = ['Accept' => 'application/json'];
        $sign = fn () => Sanctum::actingAs($merchantUser, ['merchant']);
        $host = 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/api/v1/merchant/shipments/';

        $sign();
        $this->getJson($host.$viaAgent->id, $headers)->assertOk()
            ->assertJsonPath('tracking.courier', 'شريك التوصيل')
            ->assertDontSee('وسيط الجنوب');

        $sign();
        $this->getJson($host.$viaNormal->id, $headers)->assertOk()
            ->assertJsonPath('tracking.courier', 'مندوب بغداد');

        // الرسالة التي تُكتب للتاجر بمتغيّر المندوب: تأخذ الاسم نفسه
        Tenancy::runFor($this->company, function () use ($viaAgent, $merchantUser) {
            $text = MerchantMessage::for($viaAgent->refresh(), $merchantUser);
            $this->assertStringNotContainsString('وسيط الجنوب', $text);
        });
    }

    public function test_handing_over_leaves_the_parcel_in_transit_until_their_system_takes_it_out(): void
    {
        $token = $this->key();
        $shipment = $this->ship('علي', null, [], push: false);

        $this->assertSame(ShipmentStatus::InTransit, $this->fresh($shipment)->status);
        $this->assertSame($this->agent->id, (int) $this->fresh($shipment)->delivery_courier_id);
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => (int) $this->agent->refresh()->cash_in_hand));

        $this->getJson($this->url('/me'), $this->auth($token))
            ->assertJsonPath('held.to_dispatch', 1)
            ->assertJsonPath('held.to_deliver', 0);

        $this->getJson($this->url('/shipments/'.$shipment->number), $this->auth($token))->assertOk()
            ->assertJsonPath('data.status', 'in_transit')
            ->assertJsonPath('data.actions', ['out_for_delivery']);

        // لا يُسلَّم قبل أن يخرج من نظامه
        $url = $this->url('/shipments/'.$shipment->number.'/status');
        $this->postJson($url, ['action' => 'delivered'], $this->auth($token))
            ->assertUnprocessable()->assertJsonValidationErrors('action');

        $this->postJson($url, ['action' => 'out_for_delivery'], $this->auth($token))->assertOk()
            ->assertJsonPath('data.status', 'out_for_delivery')
            ->assertJsonPath('data.actions', ['delivered', 'failed_attempt', 'postponed']);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $event = ShipmentEvent::where('shipment_id', $shipment->id)->where('to_status', 'out_for_delivery')->sole();
            $this->assertSame('in_transit', $event->from_status);
            $this->assertStringContainsString('ربط آلي', (string) $event->actor_name);
        });
    }

    public function test_a_parcel_with_the_intermediary_cannot_go_to_another_courier_or_be_taken_back_at_our_hub(): void
    {
        $shipment = $this->ship('علي', null, [], push: false);
        $other = $this->courier('مندوب بغداد');

        $given = Tenancy::runFor($this->company, fn () => app(SendOutForDelivery::class)
            ->handle(collect([$this->fresh($shipment)]), $other, $this->staff));
        $this->assertSame('مع الشركة الوسيطة', $given['skipped'][$shipment->number] ?? null);

        $scanned = Tenancy::runFor($this->company, fn () => app(ReceiveAtHub::class)->handle([$shipment->id], $this->staff));
        $this->assertSame('مع الشركة الوسيطة: تعود إلينا راجعةً فقط', $scanned['skipped'][$shipment->number] ?? null);

        $this->assertSame(ShipmentStatus::InTransit, $this->fresh($shipment)->status);
        $this->assertSame($this->agent->id, (int) $this->fresh($shipment)->delivery_courier_id);
    }

    public function test_a_parcel_sent_back_to_the_intermediary_is_in_transit_again_and_follows_the_same_path(): void
    {
        $token = $this->key();
        $shipment = $this->ship('علي');
        $url = $this->url('/shipments/'.$shipment->number.'/status');

        $this->postJson($url, ['action' => 'failed_attempt', 'reason_id' => $this->reason()->id, 'note' => 'لا يرد'], $this->auth($token))->assertOk();

        // الكول سنتر يقرّر إعادة التوصيل: يعود إلى الوسيط «بالطريق» لا «قيد التوصيل»
        $status = Tenancy::runFor($this->company, fn () => app(ProcessFailedAttempt::class)
            ->handle($this->fresh($shipment), 'redeliver', $this->staff));
        $this->assertSame(ShipmentStatus::InTransit, $status);
        $this->assertSame(ShipmentStatus::InTransit, $this->fresh($shipment)->status);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $processed = ShipmentEvent::where('shipment_id', $shipment->id)->where('event_type', 'processed')->sole();
            $this->assertSame('in_transit', $processed->to_status, 'السجلّ يكتب ما وصلت إليه الشحنة فعلاً');
        });

        $this->postJson($url, ['action' => 'out_for_delivery'], $this->auth($token))->assertOk();
        $this->postJson($url, ['action' => 'delivered'], $this->auth($token))->assertOk()->assertJsonPath('data.status', 'delivered');
    }

    public function test_a_normal_courier_still_takes_the_parcel_straight_out_for_delivery(): void
    {
        $normal = $this->courier('مندوب بغداد');

        $shipment = $this->ship('زينب', $normal);

        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($shipment)->status);
    }

    public function test_a_manual_or_bulk_move_back_to_the_shelf_is_refused_while_the_intermediary_holds_it(): void
    {
        $shipment = $this->ship('علي', null, [], push: false);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $this->expectException(ValidationException::class);

            app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::AtHub, $this->staff);
        });

        $this->assertSame(ShipmentStatus::InTransit, $this->fresh($shipment)->status);
    }
}
