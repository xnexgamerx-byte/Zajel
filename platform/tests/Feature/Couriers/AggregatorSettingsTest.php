<?php

namespace Tests\Feature\Couriers;

use App\Actions\Shipments\CreateShipment;
use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\AggregatorJob;
use App\Models\AggregatorLink;
use App\Models\AggregatorPlace;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * شاشة «ربط الشركة الوسيطة» (docs/plan/104): لصاحب «بيانات الشركة» في شركةٍ فُتحت لها الميزة. الأسرار
 * مشفّرة ولا تُعرض، ومفتاح الـ Webhook يُرى مرّةً، والمناطق تُربط بالاسم أو باليد، وما فشل يُعاد.
 */
class AggregatorSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://agg.test/v1';

    private Company $company;

    private User $owner;

    private Courier $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->owner = $this->makeUser($this->company);
        $this->agent = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C9', 'name' => 'وسيط الجنوب', 'phone' => '07721234567', 'type' => 'delivery', 'status' => 'active', 'is_intermediary' => true,
        ]));
    }

    private function url(string $path = ''): string
    {
        return 'http://zajel.'.config('zajel.tenant_domain').'/aggregator'.$path;
    }

    private function link(): AggregatorLink
    {
        return Tenancy::runFor($this->company, fn () => AggregatorLink::query()->sole());
    }

    private function save(array $over = []): TestResponse
    {
        return $this->actingAs($this->owner)->put($this->url(), $over + [
            'base_url' => self::BASE, 'username' => 'acct', 'password' => 'pass-123', 'courier_id' => $this->agent->id,
            'order_prefix' => 'ZJL_', 'package_size_id' => '1', 'webhook_header' => 'X-Hook-Key',
        ]);
    }

    private function fakeApi(): void
    {
        Http::fake([
            self::BASE.'/aggre/login' => Http::response(['status' => true, 'data' => ['token' => 'agg-tok']]),
            self::BASE.'/aggre/get-merchants*' => Http::response(['status' => true, 'data' => [
                ['id' => '55', 'username' => 'zajel_m', 'token' => 'merchant-tok', 'name' => 'متجرنا', 'mobile' => '+9647700000000', 'city_id' => '1'],
            ]]),
            self::BASE.'/merchant/cities' => Http::response(['status' => true, 'data' => [['id' => '1', 'city_name' => 'بغداد'], ['id' => '5', 'city_name' => 'البصره']]]),
            self::BASE.'/merchant/package-sizes' => Http::response(['status' => true, 'data' => [['id' => '1', 'size' => 'عادي'], ['id' => '2', 'size' => 'كبير']]]),
            self::BASE.'/merchant/statuses*' => Http::response(['status' => true, 'data' => [['id' => '3', 'status' => 'مع المندوب'], ['id' => '35', 'status' => 'مغلق']]]),
            self::BASE.'/merchant/regions*' => Http::response(['status' => true, 'data' => [['id' => '77', 'region_name' => 'عشار'], ['id' => '78', 'region_name' => 'حي الجمهورية']]]),
        ]);
    }

    public function test_only_an_owner_of_a_company_with_the_feature_opens_it(): void
    {
        // الميزة مغلقة: الشاشة مقفلة ورابطها لا يظهر في القائمة
        $this->actingAs($this->owner)->get($this->url())->assertForbidden();
        $this->actingAs($this->owner)->get('http://zajel.'.config('zajel.tenant_domain').'/settings/company')->assertDontSee($this->url(), false);

        $this->setFeature($this->company, Feature::Aggregator);
        $this->actingAs($this->owner)->get($this->url())->assertOk()->assertSee('ربط الشركة الوسيطة')
            ->assertSee(route('api.aggregator.webhook'), false);

        $ops = $this->makeUser($this->company, UserRole::Operations);
        $this->actingAs($ops)->get($this->url())->assertForbidden();
    }

    public function test_the_account_is_saved_secretly_and_connecting_fills_the_merchant_and_lists(): void
    {
        $this->setFeature($this->company, Feature::Aggregator);
        $this->fakeApi();

        // بادئةٌ بأرقام أو صغيرة مرفوضة، والإرسال لا يُفتح قبل اكتمال الربط
        $this->save(['order_prefix' => 'zj1'])->assertSessionHasErrors('order_prefix');
        $this->save(['sends_orders' => 1])->assertSessionHasErrors('sends_orders');
        $this->save()->assertSessionHasNoErrors();

        $row = DB::table('aggregator_links')->first();
        $this->assertStringNotContainsString('pass-123', (string) $row->password);
        $this->actingAs($this->owner)->get($this->url())->assertDontSee('pass-123');

        $this->actingAs($this->owner)->post($this->url('/connect'))->assertSessionHasNoErrors();

        $link = $this->link();
        $this->assertSame(['55', 'متجرنا', 'merchant-tok'], [$link->merchant_id, $link->merchant_name, $link->merchant_token]);
        $this->assertSame(['3' => 'مع المندوب', '35' => 'مغلق'], $link->catalog['statuses']);
        $this->assertSame(['1' => 'عادي', '2' => 'كبير'], $link->catalog['package_sizes']);
        $this->assertStringNotContainsString('merchant-tok', (string) DB::table('aggregator_links')->value('catalog'));

        // اكتمل: يُفتح الإرسال
        $this->save(['sends_orders' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($this->link()->sends_orders);

        // ربط الحالات يُحفظ ويُقرأ
        $this->actingAs($this->owner)->put($this->url('/statuses'), ['map' => ['3' => 'out_for_delivery', '35' => 'failed_attempt']])->assertSessionHasNoErrors();
        $this->assertSame('failed_attempt', $this->link()->actionFor('35'));
        $this->assertTrue(Tenancy::runFor($this->company, fn () => AuditLog::where('action', 'aggregator_changed')->exists()));
    }

    public function test_the_webhook_key_is_shown_once_and_works(): void
    {
        $this->setFeature($this->company, Feature::Aggregator);
        $this->save();

        $this->actingAs($this->owner)->post($this->url('/hook'))->assertSessionHasNoErrors();
        $hook = session('aggregator_hook');
        $this->assertStringStartsWith('X-Hook-Key:', $hook);
        [, $token] = explode(':', $hook, 2);

        // يظهر في الصفحة التي تلي توليده، ثم لا يظهر ثانيةً
        $this->actingAs($this->owner)->get($this->url())->assertSee($token);
        $this->actingAs($this->owner)->get($this->url())->assertDontSee($token)->assertSee('…'.substr($token, -4));
        $this->assertTrue($this->link()->webhookAccepts($token));
        $this->assertFalse($this->link()->webhookAccepts('other'));

        $this->postJson('http://zajel.'.config('zajel.tenant_domain').'/api/v1/aggregator/webhook', ['id' => '1', 'status_id' => '2'], ['X-Hook-Key' => $token])
            ->assertOk()->assertJsonPath('msg', 'ok');
    }

    public function test_areas_are_matched_by_name_and_the_rest_by_hand(): void
    {
        $this->setFeature($this->company, Feature::Aggregator);
        $this->fakeApi();
        $this->save();
        $this->actingAs($this->owner)->post($this->url('/connect'));

        $this->actingAs($this->owner)->post($this->url('/areas/auto'))->assertSessionHasNoErrors();

        [$basra, $ashar, $jumhuriya] = Tenancy::runFor($this->company, function () {
            $basra = Governorate::where('code', 'BSR')->firstOrFail();

            return [$basra, $this->area('العشار', $basra), $this->area('الجمهورية', $basra)];
        });
        $places = Tenancy::runFor($this->company, fn () => AggregatorPlace::query()->get()->groupBy('kind')->map->pluck('remote_id', 'local_id'));

        // «البصره» هي «البصرة»، و«عشار» هي «العشار»، و«حي الجمهورية» هي «الجمهورية»
        $this->assertSame('5', $places['governorate'][$basra->id]);
        $this->assertSame('77', $places['city'][$ashar]);
        $this->assertSame('78', $places['city'][$jumhuriya]);

        // وباليد: تُغيَّر منطقةٌ أو تُفكّ
        $this->actingAs($this->owner)->put($this->url('/areas'), ['city' => [$ashar => '78', $jumhuriya => '']])->assertSessionHasNoErrors();
        $places = Tenancy::runFor($this->company, fn () => AggregatorPlace::query()->where('kind', 'city')->pluck('remote_id', 'local_id'));
        $this->assertSame('78', $places[$ashar]);
        $this->assertFalse(isset($places[$jumhuriya]));

        $this->actingAs($this->owner)->get($this->url('/areas'))->assertOk()->assertSee('البصرة')->assertSee('حي الجمهورية');
    }

    public function test_the_log_shows_what_failed_and_retries_it(): void
    {
        $this->setFeature($this->company, Feature::Aggregator);
        $this->save();

        $job = Tenancy::runFor($this->company, function () {
            $merchant = $this->makeMerchant($this->company);
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'cod_amount' => 1000,
            ], $this->owner);

            return AggregatorJob::create(['shipment_id' => $shipment->id, 'action' => 'create', 'status' => 'failed',
                'attempts' => 6, 'last_error' => 'منطقة «الكرادة» غير مربوطة بمنطقةٍ عندهم.']);
        });

        $this->actingAs($this->owner)->get($this->url('/log'))->assertOk()->assertSee('منطقة «الكرادة» غير مربوطة')->assertSee('6 محاولات');
        $this->actingAs($this->owner)->post($this->url('/jobs/'.$job->id.'/retry'))->assertSessionHasNoErrors();

        $job = Tenancy::runFor($this->company, fn () => $job->refresh());
        $this->assertSame(['pending', 0, null], [$job->status, $job->attempts, $job->last_error]);
    }
}
