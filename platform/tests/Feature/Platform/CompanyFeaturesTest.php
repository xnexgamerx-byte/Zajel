<?php

namespace Tests\Feature\Platform;

use App\Actions\Billing\GenerateInvoice;
use App\Actions\Platform\SetCompanyFeature;
use App\Actions\Shipments\CreateShipment;
use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyFeature;
use App\Models\Courier;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Shipment;
use App\Models\Subscription;
use App\Models\User;
use App\Support\FeatureGate;
use App\Support\StaffNavigation;
use App\Support\Tenancy\Tenancy;
use App\Support\Theme;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * نظام كل شركةٍ من لوحة المنصّة (docs/plan/35): ميزاتٌ تُفتح وتُغلق لشركةٍ وحدها برسمٍ
 * شهريّ على فاتورتها، والجديدة مطفأةٌ حتى تُفتح؛ ومظهرٌ لكل نظام، وترتيبٌ لقوائمه.
 */
class CompanyFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Company $other;

    private Merchant $merchant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();

        $this->admin = Tenancy::runAsPlatform(fn () => User::create([
            'name' => 'مدير المنصّة', 'phone' => '07700000000',
            'password' => 'password', 'role' => UserRole::PlatformAdmin, 'is_active' => true,
        ]));

        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        $this->other = $this->makeCompany('barq', 'البرق');
        $this->makeMerchant($this->other);
    }

    private function host(?Company $company = null): string
    {
        return 'http://'.($company ?? $this->company)->slug.'.'.config('zajel.tenant_domain');
    }

    private function merchantUser(): User
    {
        return Tenancy::runFor($this->company, fn () => User::firstOrCreate(['phone' => '07790000001'], [
            'name' => 'تاجر', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));
    }

    private function decide(Feature $feature, bool|string $enabled, int|string $price = '', ?Company $company = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post('/admin/companies/'.($company ?? $this->company)->id.'/features', [
            'feature' => $feature->value, 'enabled' => $enabled ? '1' : '0', 'monthly_price' => (string) $price,
        ]);
    }

    /** @return list<string> */
    private function menuKeys(Company $company, User $user): array
    {
        // الشركة كما في قاعدة البيانات: الطلب يقرؤها من جديد كل مرّة
        $company = Tenancy::runAsPlatform(fn () => $company->fresh());

        return array_column(Tenancy::runFor($company, fn () => StaffNavigation::for($user, Request::create('/'))), 'key');
    }

    // ------------------------------------------------------------ الميزات

    public function test_a_new_feature_stays_off_until_the_platform_opens_it_for_one_company(): void
    {
        $read = ['text' => "علي حسين\n07712345678\nبغداد الكرادة"];

        // مطفأةٌ في كل شركة: لا بطاقة قراءة، ومسارها يُرَدّ بسببٍ عربيّ
        $this->actingAs($this->owner)->get($this->host().'/shipments/create')->assertOk()->assertDontSee('data-order-reader', false);
        $this->actingAs($this->owner)->postJson($this->host().'/shipments/read', $read)
            ->assertForbidden()
            ->assertJsonPath('message', 'هذه الميزة غير مفعّلة في نظام شركتكم — يفعّلها صاحب الشركة بطلبٍ إلى إدارة المنصّة.');

        $this->decide(Feature::OrderReading, true, '25 000')
            ->assertRedirect()
            ->assertSessionHas('success', 'فُتحت «قراءة الطلب من صورة أو رسالة» لشركة الزاجل بـ 25,000 د.ع شهرياً تُضاف إلى فاتورتها.');

        $this->actingAs($this->owner)->get($this->host().'/shipments/create')->assertOk()->assertSee('data-order-reader', false);
        $this->actingAs($this->owner)->postJson($this->host().'/shipments/read', $read)->assertOk()
            ->assertJsonPath('fields.recipient_phone', '07712345678');

        $merchant = $this->merchantUser();
        $this->actingAs($merchant)->get($this->host().'/portal/shipments/create')->assertOk()->assertSee('data-order-reader', false);

        // والشركة الأخرى على حالها: مطفأة
        $owner = $this->makeUser($this->other);
        $this->actingAs($owner)->get($this->host($this->other).'/shipments/create')->assertOk()->assertDontSee('data-order-reader', false);
        $this->actingAs($owner)->postJson($this->host($this->other).'/shipments/read', $read)->assertForbidden();
    }

    public function test_closing_an_original_feature_takes_its_screens_and_links_away_in_that_company_only(): void
    {
        $merchant = $this->merchantUser();

        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()->assertSee($this->host().'/shipments/import', false);
        $this->actingAs($merchant)->get($this->host().'/portal')->assertOk()->assertSee($this->host().'/portal/shipments/import', false);

        $this->decide(Feature::ExcelImport, false)->assertSessionHas('success', 'أُغلقت «رفع الشحنات من ملف Excel» في نظام الزاجل.');

        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()->assertDontSee($this->host().'/shipments/import', false);
        $this->actingAs($this->owner)->get($this->host().'/shipments/import')->assertForbidden();
        $this->actingAs($merchant)->get($this->host().'/portal')->assertOk()->assertDontSee($this->host().'/portal/shipments/import', false);
        $this->actingAs($merchant)->get($this->host().'/portal/shipments/import')->assertForbidden();

        $owner = $this->makeUser($this->other);
        $this->actingAs($owner)->get($this->host($this->other).'/shipments/import')->assertOk();
    }

    public function test_with_every_feature_closed_no_screen_links_to_a_closed_one(): void
    {
        $this->setFeature($this->company, Feature::OrderReading);

        $shipment = Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'city_id' => $this->area(), 'cod_amount' => 25_000,
        ], $this->owner));
        $courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
        ]));
        $courierUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'أحمد', 'phone' => '07720000001', 'password' => 'password',
            'role' => UserRole::Courier, 'courier_id' => $courier->id, 'is_active' => true,
        ]));

        // كل عنوانٍ تحرسه ميزة، إلى أوّل معامل فيه: «conversations/» من conversations/{conversation}
        $closed = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->getName() && FeatureGate::guarding($route->getName()))
            ->map(fn ($route) => $this->host().'/'.strstr($route->uri().'{', '{', true))
            ->unique()->values();
        $this->assertGreaterThan(10, $closed->count());

        $pages = [
            [$this->owner, '/'], [$this->owner, '/shipments'], [$this->owner, '/shipments/create'],
            [$this->owner, '/shipments/'.$shipment->id], [$this->owner, '/reports/notifications'],
            [$this->owner, '/settings/company'],
            [$this->merchantUser(), '/portal'], [$this->merchantUser(), '/portal/shipments/create'],
            [$this->merchantUser(), '/portal/shipments/'.$shipment->id],
            [$courierUser, '/courier'],
        ];

        $linked = fn () => collect($pages)->flatMap(function (array $page) use ($closed) {
            $html = $this->actingAs($page[0])->get($this->host().$page[1])->assertOk()->getContent();

            return $closed->filter(fn (string $url) => str_contains($html, $url.'"') || str_contains($html, $url.'/') || str_contains($html, $url.'?'));
        })->unique();

        // والميزات مفتوحة: هذه الصفحات تربط بشاشاتها فعلاً — فالفحص بعد إغلاقها ليس فارغاً
        $this->assertGreaterThanOrEqual(10, $linked()->count());

        foreach (Feature::cases() as $feature) {
            $this->setFeature($this->company, $feature, false);
        }

        foreach ($pages as [$user, $path]) {
            $html = $this->actingAs($user)->get($this->host().$path)->assertOk()->getContent();

            foreach ($closed as $url) {
                $this->assertStringNotContainsString($url.'"', $html, "{$path} يربط بشاشةٍ مغلقة: {$url}");
                $this->assertStringNotContainsString($url.'/', $html, "{$path} يربط بشاشةٍ مغلقة: {$url}");
                $this->assertStringNotContainsString($url.'?', $html, "{$path} يربط بشاشةٍ مغلقة: {$url}");
            }
        }
    }

    public function test_the_platform_opens_prices_and_closes_a_feature_and_audits_each_change(): void
    {
        $this->actingAs($this->admin)->get('/admin/companies/'.$this->company->id.'/system')->assertOk()
            ->assertSee('قراءة الطلب من صورة أو رسالة')
            ->assertSee('مطفأةٌ حتى تفتحها لهذه الشركة.')
            ->assertSee('إضافة');

        $this->decide(Feature::OrderReading, true, 25_000);
        $this->decide(Feature::OrderReading, true, 25_000)->assertSessionHas('success', 'لم يتغيّر شيء.');
        $this->decide(Feature::OrderReading, true, 30_000)
            ->assertSessionHas('success', 'صار رسم «قراءة الطلب من صورة أو رسالة» لشركة الزاجل: بـ 30,000 د.ع شهرياً تُضاف إلى فاتورتها.');
        $this->decide(Feature::OrderReading, false, 30_000);

        $rows = Tenancy::runAsPlatform(fn () => CompanyFeature::orderBy('id')->get());
        $this->assertSame([[true, 25_000], [true, 30_000], [false, 0]], $rows->map(fn ($row) => [$row->enabled, $row->monthly_price])->all());
        $this->assertSame([false, false, true], $rows->map(fn ($row) => $row->ends_at === null)->all());
        $this->assertSame($this->admin->id, $rows->last()->user_id);

        $audits = Tenancy::runAsPlatform(fn () => AuditLog::where('company_id', $this->company->id)->orderBy('id')->get());
        $this->assertSame(['feature_enabled', 'feature_price_changed', 'feature_disabled'], $audits->pluck('action')->all());
        // عمود JSON في MySQL يرتّب مفاتيحه: المقارنة بالمفاتيح لا بترتيبها
        $values = $audits[1]->new_values;
        ksort($values);
        $this->assertSame(['enabled' => true, 'feature' => 'order_reading', 'monthly_price' => 30_000], $values);

        // وصفحة الشركة تسمّي الميزة في سجلّها
        $this->actingAs($this->admin)->get('/admin/companies/'.$this->company->id)->assertOk()
            ->assertSee('أُغلقت ميزة')
            ->assertSee('«قراءة الطلب من صورة أو رسالة»', false);
    }

    public function test_a_fee_is_in_whole_250s_and_a_feature_must_be_known(): void
    {
        $this->decide(Feature::OrderReading, true, 25_100)->assertSessionHasErrors(['monthly_price' => 'الرسم الشهري بمضاعفات ٢٥٠ دينار.']);
        $this->decide(Feature::OrderReading, true, -250)->assertSessionHasErrors('monthly_price');
        $this->actingAs($this->admin)->post('/admin/companies/'.$this->company->id.'/features', ['feature' => 'teleport', 'enabled' => '1'])
            ->assertSessionHasErrors('feature');
        $this->assertSame(0, Tenancy::runAsPlatform(fn () => CompanyFeature::count()));
    }

    public function test_only_the_platform_decides_a_company_features(): void
    {
        $this->actingAs($this->owner)->post('/admin/companies/'.$this->company->id.'/features', [
            'feature' => 'order_reading', 'enabled' => '1', 'monthly_price' => '0',
        ])->assertForbidden();
        $this->actingAs($this->owner)->get('/admin/companies/'.$this->company->id.'/system')->assertForbidden();

        $this->assertFalse($this->company->hasFeature(Feature::OrderReading));
    }

    public function test_a_company_sees_what_runs_in_its_system_and_what_it_costs(): void
    {
        $this->setFeature($this->company, Feature::OrderReading, true, 25_000);
        $this->setFeature($this->company, Feature::AppAds, false);

        $this->actingAs($this->owner)->get($this->host().'/settings/company')->assertOk()
            ->assertSee('ميزات نظامك')
            ->assertSeeInOrder(['قراءة الطلب من صورة أو رسالة', '25,000 د.ع شهرياً', 'إعلانات التطبيق', 'غير مفعّلة']);
    }

    public function test_the_features_pages_show_where_each_runs_and_what_it_brings(): void
    {
        $this->setFeature($this->company, Feature::OrderReading, true, 25_000);

        $this->actingAs($this->admin)->get('/admin/features')->assertOk()
            ->assertSee(route('admin.features.show', 'order_reading'), false)
            ->assertSee('25,000 د.ع');

        $this->actingAs($this->admin)->get('/admin/features/order_reading')->assertOk()
            ->assertSeeInOrder(['البرق', 'مغلقة', 'الزاجل', 'مفتوحة — 25,000 د.ع شهرياً']);

        $this->actingAs($this->admin)->get('/admin/features/teleport')->assertNotFound();

        // والإيراد الشهريّ في لوحة المنصّة يحسبها
        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('منها رسوم ميزات');
    }

    // ------------------------------------------------------------ الفاتورة

    public function test_the_invoice_bills_a_feature_by_the_days_it_was_open(): void
    {
        $set = app(SetCompanyFeature::class);

        $this->travelTo(CarbonImmutable::parse('2026-03-10 15:00'));
        $set->handle($this->company, Feature::OrderReading, true, 31_000, $this->admin);

        $this->travelTo(CarbonImmutable::parse('2026-04-16 09:00'));
        $set->handle($this->company, Feature::OrderReading, false, 0, $this->admin);

        $this->travelTo(CarbonImmutable::parse('2026-05-02 10:00'));
        $march = app(GenerateInvoice::class)->handle($this->company, CarbonImmutable::parse('2026-03-01'));
        $april = app(GenerateInvoice::class)->handle($this->company, CarbonImmutable::parse('2026-04-01'));

        // آذار: من العاشر حتى آخره ٢٢ يوماً من ٣١. نيسان: حتى السادس عشر، يوم الإغلاق يُحسب
        $this->assertSame(22_000, (int) $march->features_amount);
        $this->assertSame(22_000, (int) $march->total);
        $this->assertSame(16_500, (int) $april->features_amount);
        $this->assertSame('ميزة «قراءة الطلب من صورة أو رسالة» — 22 يوماً من 31 في 2026-03', $march->items->sole()->description);
        $this->assertSame('addon', $march->items->sole()->type);

        // أيار: لا اشتراك ولا ميزة — لا فاتورة
        $this->expectException(ValidationException::class);
        app(GenerateInvoice::class)->handle($this->company, CarbonImmutable::parse('2026-05-01'));
    }

    public function test_a_price_change_bills_each_day_at_its_own_price(): void
    {
        $set = app(SetCompanyFeature::class);

        $this->travelTo(CarbonImmutable::parse('2026-05-01 00:00'));
        $set->handle($this->company, Feature::OrderReading, true, 30_000, $this->admin);
        $this->travelTo(CarbonImmutable::parse('2026-05-16 10:00'));
        $set->handle($this->company, Feature::OrderReading, true, 60_000, $this->admin);

        $this->travelTo(CarbonImmutable::parse('2026-07-01 10:00'));
        $may = app(GenerateInvoice::class)->handle($this->company, CarbonImmutable::parse('2026-05-01'));
        $june = app(GenerateInvoice::class)->handle($this->company, CarbonImmutable::parse('2026-06-01'));

        // ١٥ يوماً بثلاثين ألفاً و١٦ بستّين من ٣١: ٤٥٤٨٣٫٨ ← ٤٥٥٠٠. وحزيران كاملٌ بالسعر الجديد
        $this->assertSame(45_500, (int) $may->features_amount);
        $this->assertSame(60_000, (int) $june->features_amount);
        $this->assertSame('ميزة «قراءة الطلب من صورة أو رسالة» — 2026-06', $june->items->sole()->description);
    }

    public function test_a_free_or_closed_feature_adds_nothing_beside_the_subscription(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $plan = Plan::where('code', 'basic')->firstOrFail();
        $period = CarbonImmutable::now()->subMonth()->startOfMonth();

        Tenancy::runFor($this->company, fn () => Subscription::create([
            'plan_id' => $plan->id, 'status' => 'active', 'billing_cycle' => 'monthly', 'price' => 250_000,
            'commission_per_shipment' => 0, 'commission_percent' => 0,
            'starts_at' => $period->subMonth()->toDateString(), 'ends_at' => $period->addYear()->toDateString(),
        ]));
        $this->travelTo($period->subDays(3));
        $this->setFeature($this->company, Feature::OrderReading, true, 0);
        $this->setFeature($this->company, Feature::ExcelImport, false);
        $this->travelBack();

        $invoice = app(GenerateInvoice::class)->handle($this->company, $period);

        $this->assertSame(0, (int) $invoice->features_amount);
        $this->assertSame(250_000, (int) $invoice->total);
        $this->assertSame(['subscription'], $invoice->items->pluck('type')->all());
    }

    public function test_another_company_feature_never_reaches_this_invoice(): void
    {
        $period = CarbonImmutable::now()->subMonth()->startOfMonth();
        $this->travelTo($period->subDays(3));
        $this->setFeature($this->other, Feature::OrderReading, true, 50_000);
        $this->setFeature($this->company, Feature::OrderReading, true, 20_000);
        $this->travelBack();

        $invoice = app(GenerateInvoice::class)->handle($this->company, $period);

        $this->assertSame(20_000, (int) $invoice->features_amount);
        $this->actingAs($this->admin)->get('/admin/invoices/'.$invoice->id)->assertOk()
            ->assertSee('الميزات الإضافية')
            ->assertSee('ميزة «قراءة الطلب من صورة أو رسالة»');
    }

    // ------------------------------------------------------------ المظهر

    public function test_the_theme_paints_one_company_only(): void
    {
        $blue = '--color-primary-600: oklch(0.565 0.187 256)';

        $this->actingAs($this->admin)->post('/admin/companies/'.$this->company->id.'/theme', ['theme' => 'blue'])
            ->assertRedirect(route('admin.companies.system', $this->company).'#theme')
            ->assertSessionHas('success', 'صار مظهر نظام الزاجل: أزرق.');

        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()->assertSee($blue, false);
        $this->actingAs($this->merchantUser())->get($this->host().'/portal')->assertOk()->assertSee($blue, false);
        $this->get($this->host().'/track')->assertOk()->assertSee($blue, false);

        $owner = $this->makeUser($this->other);
        $this->actingAs($owner)->get($this->host($this->other).'/')->assertOk()->assertDontSee('--color-primary-600:', false);
        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertDontSee($blue, false);

        $audit = Tenancy::runAsPlatform(fn () => AuditLog::where('action', 'theme_changed')->sole());
        $this->assertSame(['theme' => 'wahaj'], $audit->old_values);
        $this->assertSame(['theme' => 'blue'], $audit->new_values);

        $this->actingAs($this->admin)->post('/admin/companies/'.$this->company->id.'/theme', ['theme' => 'neon'])
            ->assertSessionHasErrors('theme');
    }

    public function test_the_logo_theme_follows_the_company_colour(): void
    {
        Tenancy::runAsPlatform(fn () => $this->company->update(['primary_color' => '#1D4ED8']));
        $this->actingAs($this->admin)->post('/admin/companies/'.$this->company->id.'/theme', ['theme' => 'logo']);

        $logo = Theme::make('logo', '#1D4ED8');
        $this->assertSame('logo', $logo->key);
        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()
            ->assertSee('--color-primary-600: '.$logo->shades[600], false);
    }

    public function test_every_theme_keeps_buttons_and_links_readable(): void
    {
        $logos = ['#0F766E', '#FFD400', '#1D4ED8', '#808080', '#E11D48', '#000000', '#FFFFFF', '#7FFF00'];

        foreach (array_keys(Theme::NAMES) as $key) {
            foreach ($key === Theme::FROM_LOGO ? $logos : [null] as $logo) {
                $theme = Theme::make($key, $logo);
                $this->assertSame($key, $theme->key);
                $this->assertCount(11, $theme->shades);

                // نصٌّ أبيض على الزرّ، والرابط على الأبيض وعلى الخلفيات الفاتحة: AA
                foreach ([[600, null], [700, null], [700, 50], [700, 100], [900, 100]] as [$text, $on]) {
                    $this->assertGreaterThanOrEqual(4.5, $theme->contrast($text, $on), "{$key} {$logo}: {$text} على ".($on ?? 'الأبيض'));
                }
            }
        }

        $this->assertSame('wahaj', Theme::make('neon')->key);
        $this->assertSame('wahaj', Theme::make('logo', null)->key);
        $this->assertSame('', Theme::make('wahaj')->css());
    }

    // ------------------------------------------------------------ ترتيب القوائم

    public function test_the_platform_orders_one_company_menus_and_links(): void
    {
        $default = ['home', 'shipments', 'delivery', 'returns', 'money', 'position', 'reports', 'followup', 'settings'];
        $this->assertSame($default, $this->menuKeys($this->company, $this->owner));

        $move = fn (string $value) => $this->actingAs($this->admin)
            ->post('/admin/companies/'.$this->company->id.'/navigation', ['move' => $value]);

        $move('menu|reports|up')->assertRedirect(route('admin.companies.system', $this->company).'#menus');
        $move('menu|reports|up');
        $move('menu|home|up'); // الأولى لا تصعد

        $this->assertSame(['home', 'shipments', 'delivery', 'returns', 'reports', 'money', 'position', 'followup', 'settings'],
            $this->menuKeys($this->company, $this->owner));
        $this->assertSame($default, $this->menuKeys($this->other, $this->makeUser($this->other)));

        // والرابط في قائمته: «شحنة جديدة» قبل «كل الشحنات»
        $move('link|shipments|shipments.create|up')
            ->assertRedirect(route('admin.companies.system', $this->company).'#menu-shipments')
            ->assertSessionHas('opened', 'shipments');
        $fresh = Tenancy::runAsPlatform(fn () => $this->company->fresh());
        $shipments = collect(Tenancy::runFor($fresh, fn () => StaffNavigation::for($this->owner, Request::create('/'))))
            ->firstWhere('key', 'shipments');
        $this->assertSame(['شحنة جديدة', 'كل الشحنات'], array_slice(array_column($shipments['links'], 'label'), 0, 2));

        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()
            ->assertSeeInOrder(['التقارير', 'الحسابات المالية', 'الموقف المالي والفروع']);

        $this->actingAs($this->admin)->post('/admin/companies/'.$this->company->id.'/navigation', ['reset' => '1'])
            ->assertSessionHas('success', 'عادت قوائم موظّفي الشركة إلى ترتيبها الأصليّ.');
        $this->assertSame($default, $this->menuKeys($this->company, $this->owner));
    }

    public function test_an_unknown_move_changes_nothing(): void
    {
        foreach (['menu|nowhere|up', 'menu|reports|sideways', 'link|shipments|teleport|up', 'link|reports', ''] as $value) {
            $this->actingAs($this->admin)->post('/admin/companies/'.$this->company->id.'/navigation', ['move' => $value])
                ->assertSessionHasErrors('move');
        }

        $this->assertNull(Tenancy::runAsPlatform(fn () => $this->company->fresh()->setting('navigation')));
    }

    public function test_an_order_saved_before_a_new_menu_or_link_still_shows_everything(): void
    {
        Tenancy::runAsPlatform(fn () => $this->company->update(['settings' => ['navigation' => [
            'menus' => ['settings', 'gone', 'home'],
            'links' => ['shipments' => ['shipments.trash', 'nothing.here']],
        ]]]));

        $keys = $this->menuKeys($this->company, $this->owner);
        $this->assertSame(['settings', 'home', 'shipments', 'delivery'], array_slice($keys, 0, 4));
        $this->assertCount(9, $keys);

        $shipments = StaffNavigation::ordered(Tenancy::runAsPlatform(fn () => $this->company->fresh()))['shipments'][2];
        $this->assertSame('shipments.trash', $shipments[0][0]);
        $this->assertCount(count(StaffNavigation::menus()['shipments'][2]), $shipments);
    }
}
