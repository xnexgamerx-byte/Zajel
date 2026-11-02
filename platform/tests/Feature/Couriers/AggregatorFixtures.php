<?php

namespace Tests\Feature\Couriers;

use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\SendOutForDelivery;
use App\Enums\Feature;
use App\Models\AggregatorEvent;
use App\Models\AggregatorJob;
use App\Models\AggregatorLink;
use App\Models\AggregatorPlace;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * ما تحتاجه اختبارات الربط مع الشركة الوسيطة (docs/plan/104، 106): شركةٌ فُتحت لها الميزة، ومندوبٌ وسيط
 * مربوط، ونظامهم مزيّفاً كما في وثائقهم، وما يرسلونه بالـ Webhook.
 */
trait AggregatorFixtures
{
    private const BASE = 'https://agg.test/v1';

    private const HOOK = 'hook-secret-123';

    private Company $company;

    private User $staff;

    private Courier $agent;

    private AggregatorLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->staff = $this->makeUser($this->company);
        $this->agent = $this->courier('وسيط الجنوب', intermediary: true);
        $this->setFeature($this->company, Feature::Aggregator);
        $this->link = $this->link($this->company, $this->agent);
    }

    private function courier(string $name, bool $intermediary = false, ?Company $company = null): Courier
    {
        return Tenancy::runFor($company ?? $this->company, fn () => Courier::create([
            'code' => 'C'.random_int(100, 999), 'name' => $name, 'phone' => '0772'.random_int(1_000_000, 9_999_999),
            'type' => 'delivery', 'status' => 'active', 'is_intermediary' => $intermediary,
            'commission_per_delivery' => 6_000, 'commission_per_return' => 2_000,
        ]));
    }

    private function link(Company $company, Courier $courier, string $hook = self::HOOK): AggregatorLink
    {
        return Tenancy::runFor($company, function () use ($company, $courier, $hook) {
            $basra = Governorate::where('code', 'BSR')->firstOrFail();
            AggregatorPlace::create(['kind' => 'governorate', 'local_id' => $basra->id, 'remote_id' => '5', 'remote_name' => 'البصرة']);
            AggregatorPlace::create(['kind' => 'city', 'local_id' => $this->area('العشار', $basra), 'remote_id' => '77', 'remote_name' => 'العشار']);

            return AggregatorLink::create([
                'courier_id' => $courier->id, 'base_url' => self::BASE,
                'username' => 'acct', 'password' => 'pass-123',
                'merchant_id' => '55', 'merchant_name' => $company->name, 'merchant_token' => 'merchant-tok',
                'order_prefix' => 'ZJL_', 'package_size_id' => '1',
                'webhook_header' => 'X-Hook-Key', 'webhook_token_hash' => hash('sha256', $hook), 'webhook_token_last4' => substr($hook, -4),
                'sends_orders' => true,
            ]);
        });
    }

    /** سبب الشحنة كما يراه الكول سنتر والتاجر: نصّ حالتهم (docs/plan/107) */
    private function reasonName(Shipment $shipment): ?string
    {
        return Tenancy::runFor($this->company, fn () => FailureReason::query()->whereKey($shipment->refresh()->last_failure_reason_id)->value('name_ar'));
    }

    /** شحنةٌ للعشار في البصرة سُلِّمت لمندوب (الوسيط افتراضاً): «بالطريق» إليه */
    private function ship(?Courier $courier = null, array $extra = []): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($courier, $extra) {
            $basra = Governorate::where('code', 'BSR')->firstOrFail();
            $shipment = app(CreateShipment::class)->handle(array_merge([
                'merchant_id' => $this->makeMerchant($this->company, 'M'.random_int(1000, 9999))->id,
                'recipient_name' => 'علي', 'recipient_phone' => '07801234567', 'recipient_phone_alt' => '07701112233',
                'governorate_id' => $basra->id, 'city_id' => $this->area('العشار', $basra),
                'address' => 'قرب السوق', 'cod_amount' => 40_000, 'pieces_count' => 2,
            ], $extra), $this->staff);

            app(SendOutForDelivery::class)->handle(collect([$shipment->refresh()]), $courier ?? $this->agent, $this->staff);

            return $shipment->refresh();
        });
    }

    private function fresh(Shipment $shipment): Shipment
    {
        return Tenancy::runFor($this->company, fn () => $shipment->refresh());
    }

    private function jobs(): Collection
    {
        return Tenancy::runFor($this->company, fn () => AggregatorJob::query()->orderBy('id')->get());
    }

    /** نظامهم كما في وثائقهم: الدخول، والإنشاء الجماعي، وغيرهما */
    private function fakeApi(array $extra = []): void
    {
        Http::fake($extra + [
            self::BASE.'/aggre/login' => Http::response(['status' => true, 'errNum' => 'S000', 'msg' => 'ok', 'data' => ['token' => 'agg-tok']]),
            self::BASE.'/aggre/create-bulk-orders*' => function (HttpRequest $request) {
                $orders = json_decode($this->field($request, 'data'), true);

                return Http::response(['status' => true, 'errNum' => 'S000', 'msg' => 'Done Successfully.', 'data' => [
                    'succeed' => array_map(fn ($o) => ['id' => 9000 + (int) $o['metadata'], 'company_order_id' => $o['company_order_id'], 'metadata' => $o['metadata']], $orders),
                    'failed' => [],
                ]]);
            },
            self::BASE.'/aggre/receive-returned-order*' => Http::response(['status' => true, 'errNum' => 'S000', 'msg' => 'Done Successfully.']),
        ]);
    }

    /** حقلٌ من طلب multipart */
    private function field(HttpRequest $request, string $name): ?string
    {
        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === $name) {
                return (string) $part['contents'];
            }
        }

        return null;
    }

    private function hook(array $payload, string $token = self::HOOK, ?Company $company = null): TestResponse
    {
        $host = 'http://'.($company ?? $this->company)->slug.'.'.config('zajel.tenant_domain');

        return $this->postJson($host.'/api/v1/aggregator/webhook', $payload, ['X-Hook-Key' => $token]);
    }

    private function order(Shipment $shipment, array $over = []): array
    {
        return $over + [
            'id' => (string) $shipment->aggregator_order_id ?: '9'.$shipment->id,
            'company_order_id' => 'ZJL_'.$shipment->number,
            'client_name' => 'علي', 'client_mobile' => '+9647801234567',
            'status_id' => '2', 'status' => 'تم استلام الطلب من قبل المندوب', 'deliver_done' => 0,
            'price' => '40000', 'old_price' => -1, 'issue_notes' => 'لا یوجد', 'send_to_store' => 0,
            'updated_at' => now()->toDateTimeString(),
        ];
    }

    /** شحنةٌ عند الوسيط أُنشئت عندهم وخرجت مع مندوبهم «قيد التوصيل» */
    private function outWithThem(): Shipment
    {
        $shipment = $this->ship();
        $this->artisan('aggregator:sync');
        $shipment = $this->fresh($shipment);
        $this->hook($this->order($shipment, ['status_id' => '3', 'updated_at' => '2026-11-01 08:00:00']))->assertOk();

        return $this->fresh($shipment);
    }

    private function map(array $map): void
    {
        Tenancy::runFor($this->company, fn () => $this->link->forceFill(['status_map' => $map])->save());
    }

    private function lastResult(): string
    {
        return Tenancy::runFor($this->company, fn () => AggregatorEvent::latest('id')->first()->result);
    }
}
