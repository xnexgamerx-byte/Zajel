<?php

namespace Tests\Feature\Partners;

use App\Actions\Partners\HandOverToPartner;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Governorate;
use App\Models\Partner;
use App\Models\PartnerKey;
use App\Models\PartnerReport;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مفتاح الشريك (docs/plan/64): يرى طروده بس، ويحدّث حالاتها بالترتيب المسموح، ولا يلمس شيئاً آخر.
 */
class PartnerApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $staff;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->staff = $this->makeUser($this->company);
        $this->partner = Tenancy::runFor($this->company, fn () => Partner::create(['name' => 'شركة الوسيط', 'status' => 'active']));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function url(string $path): string
    {
        return $this->host().'/api/v1/partner'.$path;
    }

    private function key(array $abilities = [PartnerKey::READ, PartnerKey::STATUS], ?\DateTimeInterface $expires = null, ?array $ips = null): array
    {
        return Tenancy::runFor($this->company, fn () => PartnerKey::issue(
            $this->partner, 'فرع البصرة', $abilities, $expires ? Carbon::instance($expires) : now()->addMonths(6), $ips, $this->staff,
        ));
    }

    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /** طرد عند الفرع (بالمخزن)، ويُسلَّم للشريك إن طُلب */
    private function ship(string $name, bool $handOver = false): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($name, $handOver) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->makeMerchant($this->company, 'M'.random_int(1000, 9999))->id,
                'recipient_name' => $name, 'recipient_phone' => '07801234567',
                'governorate_id' => $this->basra()->id, 'address' => 'البصرة، العشار', 'cod_amount' => 40_000,
            ], $this->staff);
            app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->staff);
            app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::AtHub, $this->staff);

            if ($handOver) {
                app(HandOverToPartner::class)->handle($this->partner, [$shipment->id], $this->staff);
            }

            return $shipment->refresh();
        });
    }

    private function basra()
    {
        return Governorate::where('code', 'BSR')->firstOrFail();
    }

    private function statusOf(Shipment $shipment): ShipmentStatus
    {
        return Tenancy::runFor($this->company, fn () => $shipment->refresh()->status);
    }

    public function test_a_key_sees_and_moves_only_the_shipments_handed_to_its_partner(): void
    {
        ['token' => $token] = $this->key();
        $mine = $this->ship('علي', handOver: true);
        $other = $this->ship('زينب');

        $this->getJson($this->url('/me'), $this->auth($token))->assertOk()
            ->assertJsonPath('partner', 'شركة الوسيط')
            ->assertJsonPath('held.total', 1);

        $list = $this->getJson($this->url('/shipments'), $this->auth($token))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $mine->number)
            ->assertJsonPath('data.0.status', 'in_transit')
            ->assertJsonPath('data.0.next_statuses.0', 'at_hub');

        // لا مبلغ ولا أجر ولا تاجر في الرد: الشريك لا يرى حسابنا
        $item = $list->json('data.0');
        foreach (['cod_amount', 'delivery_fee', 'merchant', 'courier', 'settlement', 'amount'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $item);
        }
        $this->assertStringNotContainsString($other->number, $list->getContent());

        // طرد غيره: لا يُكشف إن كان موجوداً
        $this->getJson($this->url('/shipments/'.$other->number), $this->auth($token))->assertNotFound();
        $this->postJson($this->url('/shipments/'.$other->number.'/status'), ['status' => 'at_hub'], $this->auth($token))->assertNotFound();

        // استلام ثم خروج للتوصيل
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'at_hub', 'lat' => 30.5, 'lng' => 47.8], $this->auth($token))
            ->assertOk()->assertJsonPath('data.status', 'at_hub');
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'out_for_delivery'], $this->auth($token))
            ->assertOk()->assertJsonPath('data.status', 'out_for_delivery');
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->statusOf($mine));

        // الحدث يذكر الشريك ومفتاحه، لا موظفاً
        Tenancy::runFor($this->company, function () use ($mine) {
            $event = $mine->events()->where('to_status', 'out_for_delivery')->latest('id')->firstOrFail();
            $this->assertSame('api', $event->actor_type);
            $this->assertStringContainsString('شركة الوسيط', (string) $event->actor_name);
            // العلاقة مرتبة تصاعدياً، فنعكس الترتيب لنأخذ الأحدث
            $arrival = $mine->events()->where('to_status', 'at_hub')->reorder('id', 'desc')->firstOrFail();
            $this->assertSame(30.5, (float) $arrival->lat);
        });
    }

    public function test_illegal_transitions_and_unknown_fields_are_refused(): void
    {
        ['token' => $token] = $this->key();
        $mine = $this->ship('علي', handOver: true);
        $headers = $this->auth($token);

        // لا يقفز: من «في الطريق» إلى «خرج للتوصيل» بلا استلام
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'out_for_delivery'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        // «تالف» و«مفقود» ليسا من صلاحيته
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'lost'], $headers)
            ->assertUnprocessable();

        // حقلٌ غير مسموح (المبلغ) يُرفض لا يُتجاهل
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'at_hub', 'cod_amount' => 1], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('fields');

        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'at_hub'], $headers)->assertOk();
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'out_for_delivery'], $headers)->assertOk();

        // التأجيل يحتاج سبباً مكتوباً
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'postponed'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'postponed', 'note' => 'الزبون طلب الغد'], $headers)
            ->assertOk();
    }

    public function test_delivered_is_a_report_that_moves_no_money_until_we_approve(): void
    {
        ['token' => $token] = $this->key();
        $mine = $this->ship('علي', handOver: true);
        $headers = $this->auth($token);
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'at_hub'], $headers)->assertOk();
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'out_for_delivery'], $headers)->assertOk();

        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'delivered', 'note' => 'سُلّم للزبون'], $headers)
            ->assertStatus(202)->assertJsonPath('report.status', 'pending');

        // ما زال قيد التوصيل، ولا تقرير ثانٍ قبل قرارنا
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->statusOf($mine));
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'delivered'], $headers)
            ->assertUnprocessable();

        // نوافق من الموظف: الآن يُسلَّم ويُحسب
        $report = Tenancy::runFor($this->company, fn () => PartnerReport::query()->where('shipment_id', $mine->id)->pending()->firstOrFail());
        $this->actingAs($this->staff)->post($this->host().'/partner-reports/'.$report->id.'/approve')->assertRedirect();

        $this->assertSame(ShipmentStatus::Delivered, $this->statusOf($mine));
        Tenancy::runFor($this->company, function () use ($mine, $report) {
            $this->assertNull($mine->refresh()->partner_id);
            $this->assertSame('approved', $report->refresh()->decision);
        });
    }

    public function test_rejecting_a_report_keeps_the_shipment_with_the_partner(): void
    {
        ['token' => $token] = $this->key();
        $mine = $this->ship('علي', handOver: true);
        $headers = $this->auth($token);
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'at_hub'], $headers)->assertOk();
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'out_for_delivery'], $headers)->assertOk();
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'delivered'], $headers)->assertStatus(202);

        $report = Tenancy::runFor($this->company, fn () => PartnerReport::query()->where('shipment_id', $mine->id)->pending()->firstOrFail());
        $this->actingAs($this->staff)->post($this->host().'/partner-reports/'.$report->id.'/reject', ['note' => 'لا توقيع للزبون'])->assertRedirect();

        $this->assertSame(ShipmentStatus::OutForDelivery, $this->statusOf($mine));
        Tenancy::runFor($this->company, fn () => $this->assertSame($this->partner->id, $mine->refresh()->partner_id));
    }

    public function test_the_key_is_stored_hashed_and_each_limit_is_enforced(): void
    {
        ['key' => $key, 'token' => $token] = $this->key();

        // المفتاح لا يُقرأ من القاعدة: المخزَّن بصمته فقط
        $this->assertNotSame($token, $key->token_hash);
        $this->assertSame(PartnerKey::hash($token), $key->token_hash);
        $this->assertDatabaseMissing('partner_keys', ['token_hash' => $token]);

        // بلا مفتاح، أو بمفتاحٍ مزوّر، أو بمفتاحٍ من شركةٍ أخرى
        $this->getJson($this->url('/me'))->assertUnauthorized();
        $this->getJson($this->url('/me'), $this->auth('zjp_'.str_repeat('x', 40)))->assertUnauthorized();

        // ملغى: يُرفض فوراً
        Tenancy::runFor($this->company, fn () => $key->revoke());
        $this->getJson($this->url('/me'), $this->auth($token))->assertUnauthorized();

        // منتهٍ
        ['token' => $old] = $this->key(expires: now()->subDay());
        $this->getJson($this->url('/me'), $this->auth($old))->assertUnauthorized();

        // عنوانٌ غير مسموح
        ['token' => $fenced] = $this->key(ips: ['203.0.113.10']);
        $this->getJson($this->url('/me'), $this->auth($fenced))->assertForbidden();
    }

    public function test_a_key_without_status_ability_only_reads(): void
    {
        ['token' => $token] = $this->key([PartnerKey::READ]);
        $mine = $this->ship('علي', handOver: true);

        $this->getJson($this->url('/shipments'), $this->auth($token))->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'at_hub'], $this->auth($token))->assertForbidden();
        $this->assertSame(ShipmentStatus::InTransit, $this->statusOf($mine));
    }

    public function test_a_key_is_stamped_with_its_last_use(): void
    {
        ['key' => $key, 'token' => $token] = $this->key();
        $this->assertNull($key->last_used_at);

        $this->getJson($this->url('/me'), $this->auth($token))->assertOk();

        Tenancy::runFor($this->company, fn () => $this->assertNotNull($key->refresh()->last_used_at));
    }

    public function test_only_shipments_at_the_hub_go_to_the_partner(): void
    {
        $fresh = Tenancy::runFor($this->company, function () {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->makeMerchant($this->company, 'M9999')->id,
                'recipient_name' => 'حسن', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->basra()->id, 'address' => 'البصرة', 'cod_amount' => 10_000,
            ], $this->staff);

            return $shipment->refresh();
        });

        $result = Tenancy::runFor($this->company, fn () => app(HandOverToPartner::class)->handle($this->partner, [$fresh->id], $this->staff));

        $this->assertSame([], $result['moved']);
        $this->assertArrayHasKey($fresh->number, $result['skipped']);
        Tenancy::runFor($this->company, fn () => $this->assertNull($fresh->refresh()->partner_id));
    }

    public function test_returns_come_back_from_the_partner_and_leave_its_list(): void
    {
        ['token' => $token] = $this->key();
        $mine = $this->ship('علي', handOver: true);
        $headers = $this->auth($token);
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'at_hub'], $headers)->assertOk();
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'out_for_delivery'], $headers)->assertOk();
        // الراجع يمرّ بالفشل أوّلاً، كما يحدث عند الزبون الذي رفض
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'failed_attempt', 'note' => 'الزبون رفض الاستلام'], $headers)->assertOk();
        $this->postJson($this->url('/shipments/'.$mine->number.'/status'), ['status' => 'returning', 'note' => 'رجوع للتاجر'], $headers)->assertOk();

        $this->getJson($this->url('/shipments'), $headers)->assertJsonCount(1, 'data');

        $this->actingAs($this->staff)->post($this->host().'/partners/'.$this->partner->id.'/receive-back', ['numbers' => $mine->number])
            ->assertRedirect();

        $this->getJson($this->url('/shipments'), $headers)->assertJsonCount(0, 'data');
        Tenancy::runFor($this->company, fn () => $this->assertNull($mine->refresh()->partner_id));
    }

    public function test_the_company_owner_creates_a_partner_and_sees_the_token_once(): void
    {
        $owner = $this->staff;
        $this->actingAs($owner)->get($this->host().'/partners')->assertOk()->assertSee('الشركاء الذين نسلّم لهم الطرود');

        $this->actingAs($owner)->post($this->host().'/partners', ['name' => 'وسيط الجنوب'])->assertRedirect();
        $partner = Tenancy::runFor($this->company, fn () => Partner::where('name', 'وسيط الجنوب')->firstOrFail());

        $response = $this->actingAs($owner)->post($this->host().'/partners/'.$partner->id.'/keys', [
            'name' => 'فرع البصرة',
            'abilities' => ['shipments.read', 'shipments.status'],
            'expires_at' => now()->addMonths(3)->toDateString(),
        ])->assertRedirect();

        $token = session('partner_token', null);
        $response->assertSessionHas('partner_token');
        $this->assertStringStartsWith('zjp_', (string) $token);

        // يظهر مرّةً واحدة بعد الإنشاء، ثم لا يُعرض كاملاً أبداً
        $this->actingAs($owner)->get($this->host().'/partners/'.$partner->id)->assertOk()
            ->assertSee((string) $token)->assertSee('فرع البصرة');
        $this->actingAs($owner)->get($this->host().'/partners/'.$partner->id)->assertOk()
            ->assertDontSee((string) $token)->assertSee('فرع البصرة');
    }
}
