<?php

namespace Tests\Feature\Couriers;

use App\Actions\Shipments\CreateShipment;
use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\AggregatorJob;
use App\Models\AggregatorLink;
use App\Models\AggregatorPlace;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $this->save()->assertSessionHasNoErrors();

        // الإرسال لا يُفتح ناقصاً: يُحفظ الباقي كلّه ويبقى الإرسال مغلقاً مع ما ينقصه
        $this->save(['sends_orders' => 1])->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'لم يُفتح') && str_contains($m, 'ينقصه التاجر.'));
        $this->assertFalse($this->link()->sends_orders);
        $this->assertSame('ZJL_', $this->link()->order_prefix);

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

        // الصفحة العامّة بالمحافظات وعدّادها، ومناطق المحافظة في صفحتها
        $this->actingAs($this->owner)->get($this->url('/areas'))->assertOk()->assertSee('البصرة');
        $this->actingAs($this->owner)->get($this->url('/areas/'.$basra->id.'?show=all&q='.urlencode('العشار')))->assertOk()
            ->assertSee('حي الجمهورية')->assertSee('العشار');
    }

    public function test_an_ambiguous_or_missing_name_is_left_for_a_person_not_guessed(): void
    {
        $this->setFeature($this->company, Feature::Aggregator);
        // عندهم منطقتان بالاسم نفسه تقريباً: «العشار» و«عشار» — لا تُربط عشارنا بإحداهما بالتخمين.
        // تُسجَّل قبل العامّة: أوّل محاكاةٍ تطابق الطلب هي التي تُجيب
        Http::fake([self::BASE.'/merchant/regions*' => Http::response(['status' => true, 'data' => [
            ['id' => '77', 'region_name' => 'العشار'], ['id' => '80', 'region_name' => 'عشار'], ['id' => '78', 'region_name' => 'الجمهورية'],
        ]])]);
        $this->fakeApi();
        $this->save();
        $this->actingAs($this->owner)->post($this->url('/connect'));

        $this->actingAs($this->owner)->post($this->url('/areas/auto'))->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'يشبه أكثر من منطقةٍ') && str_contains($m, 'لا اسم مثلها'));

        [$ashar, $jumhuriya] = Tenancy::runFor($this->company, function () {
            $basra = Governorate::where('code', 'BSR')->firstOrFail();

            return [$this->area('العشار', $basra), $this->area('الجمهورية', $basra)];
        });
        $cities = Tenancy::runFor($this->company, fn () => AggregatorPlace::query()->where('kind', 'city')->pluck('remote_id', 'local_id'));
        $this->assertFalse(isset($cities[$ashar]));
        $this->assertSame('78', $cities[$jumhuriya]);

        // ما جُلب يبقى محفوظاً: لا يُجلب ثانيةً عند الإعادة
        $before = Http::recorded()->count();
        $this->actingAs($this->owner)->post($this->url('/areas/auto'));
        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_verifying_finds_what_is_unmapped_and_what_their_lists_no_longer_have(): void
    {
        $this->setFeature($this->company, Feature::Aggregator);
        $this->fakeApi();
        $this->save();
        $this->actingAs($this->owner)->post($this->url('/connect'));
        $this->actingAs($this->owner)->post($this->url('/areas/auto'));

        // رُبطت منطقةٌ بما لم يعد عندهم، وأخرى بمنطقةٍ تشاركها غيرها
        [$ashar, $jumhuriya] = Tenancy::runFor($this->company, function () {
            $basra = Governorate::where('code', 'BSR')->firstOrFail();

            return [$this->area('العشار', $basra), $this->area('الجمهورية', $basra)];
        });
        Tenancy::runFor($this->company, function () use ($ashar) {
            AggregatorPlace::query()->where('kind', 'city')->where('local_id', $ashar)->update(['remote_id' => '999']);
            AggregatorPlace::create(['kind' => 'city', 'local_id' => $this->area('المعقل', Governorate::where('code', 'BSR')->firstOrFail()), 'remote_id' => '78']);
        });

        $this->actingAs($this->owner)->post($this->url('/areas/verify'))->assertSessionHasNoErrors();
        $check = session('aggregator_check');

        $this->assertSame([], $check['gone_cities']);
        $this->assertSame(['البصرة — العشار'], $check['gone_regions']);
        $this->assertSame(['حي الجمهورية ← الجمهورية، المعقل'], $check['shared']);
        $this->assertGreaterThan(0, $check['unmapped']);
        // بغداد والبصرة رُبطتا بالاسم، وبقيت ست عشرة
        $this->assertSame(16, $check['governorates_left']);

        // لا يُغيّر الفحص شيئاً
        $this->assertSame('999', Tenancy::runFor($this->company, fn () => AggregatorPlace::query()->where('local_id', $ashar)->where('kind', 'city')->value('remote_id')));
        $this->actingAs($this->owner)->get($this->url('/areas'))->assertOk();
    }

    public function test_what_is_left_is_linked_by_the_longest_start_of_its_name_and_marked_for_review(): void
    {
        $this->setFeature($this->company, Feature::Aggregator);
        $this->save();

        $ids = Tenancy::runFor($this->company, function () {
            $sal = Governorate::where('code', 'SAL')->firstOrFail();
            $baghdad = $this->baghdad();
            $make = fn ($name, $governorate) => City::create(['governorate_id' => $governorate->id, 'company_id' => Tenancy::id(), 'name_ar' => $name, 'is_active' => true])->id;

            AggregatorLink::query()->sole()->forceFill(['catalog' => [
                'cities' => ['1' => 'بغداد', '9' => 'صلاح الدين'],
                'regions' => [
                    '9' => ['30' => 'دجيل', '31' => 'بلد', '32' => 'بلد', '33' => 'سامراء', '34' => 'تكريت'],
                    '1' => ['40' => 'مدينة', '41' => 'مدينة الصدر', '42' => 'الكرادة'],
                ],
            ]])->save();
            AggregatorPlace::create(['kind' => 'governorate', 'local_id' => $sal->id, 'remote_id' => '9']);
            AggregatorPlace::create(['kind' => 'governorate', 'local_id' => $baghdad->id, 'remote_id' => '1']);

            // ربطه أحدٌ بيده: لا يُمسّ ولو كان لاسمه بدايةٌ عندهم
            $tikrit = $this->area('تكريت', $sal);
            AggregatorPlace::create(['kind' => 'city', 'local_id' => $tikrit, 'remote_id' => '33', 'method' => 'manual']);

            return [
                'hatimiya' => $make('دجيل الحاتمية', $sal),
                'akwakh' => $make('دجيل اكواخ الحمزه', $sal),
                'tariq' => $make('مدينة الصدر حي طارق', $baghdad),
                'balad' => $make('بلد الشرقية', $sal),
                'far' => $make('قرية لا يعرفونها', $sal),
                'tikrit' => $tikrit,
                'sal' => $sal,
            ];
        });

        $this->actingAs($this->owner)->post($this->url('/areas/prefix'))->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'تحتمل أكثر من منطقةٍ') && str_contains($m, 'لا بداية لها'))
            ->assertSessionHas('aggregator_prefix', fn ($lines) => in_array('صلاح الدين — دجيل الحاتمية ← دجيل', $lines, true));

        $places = Tenancy::runFor($this->company, fn () => AggregatorPlace::query()->where('kind', 'city')->get()->keyBy('local_id'));

        // «دجيل …» كلّها «دجيل»، و«مدينة الصدر حي طارق» هي «مدينة الصدر» لا «مدينة»
        $this->assertSame(['30', 'prefix'], [$places[$ids['hatimiya']]->remote_id, $places[$ids['hatimiya']]->method]);
        $this->assertSame('30', $places[$ids['akwakh']]->remote_id);
        $this->assertSame(['41', 'مدينة الصدر'], [$places[$ids['tariq']]->remote_id, $places[$ids['tariq']]->remote_name]);

        // «بلد» عندهم منطقتان: لا تخمين. وما لا بداية له عندهم يبقى. وما رُبط باليد كما هو
        $this->assertFalse(isset($places[$ids['balad']]));
        $this->assertFalse(isset($places[$ids['far']]));
        $this->assertSame(['33', 'manual'], [$places[$ids['tikrit']]->remote_id, $places[$ids['tikrit']]->method]);
        $this->assertTrue(Tenancy::runFor($this->company, fn () => AuditLog::where('action', 'aggregator_areas_prefix')->exists()));

        // للمراجعة: تُعرض وحدها معلَّمةً، والحفظ باليد يثبّتها فيزول العلم
        $this->actingAs($this->owner)->get($this->url('/areas/'.$ids['sal']->id.'?show=prefix&q='.urlencode('دجيل')))->assertOk()
            ->assertSee('دجيل الحاتمية')->assertSee('بأول الاسم')->assertDontSee('بلد الشرقية');
        // «الدجيل» اسمها كاملاً عندهم: رُبطت بالاسم لا بأول الاسم
        $this->assertSame('name', Tenancy::runFor($this->company, fn () => AggregatorPlace::query()->where('kind', 'city')->where('local_id', $this->area('الدجيل', $ids['sal']))->value('method')));
        $this->actingAs($this->owner)->put($this->url('/areas'), ['city' => [$ids['hatimiya'] => '30']])->assertSessionHasNoErrors();
        $this->assertSame('manual', Tenancy::runFor($this->company, fn () => AggregatorPlace::query()->where('kind', 'city')->where('local_id', $ids['hatimiya'])->value('method')));

        // الضغط ثانيةً لا يغيّر ما رُبط
        $before = Tenancy::runFor($this->company, fn () => AggregatorPlace::query()->count());
        $this->actingAs($this->owner)->post($this->url('/areas/prefix'))->assertSessionHas('success', fn ($m) => str_contains($m, 'رُبط بأول الاسم 0'));
        $this->assertSame($before, Tenancy::runFor($this->company, fn () => AggregatorPlace::query()->count()));
    }

    public function test_the_links_go_out_in_a_file_and_a_reviewed_file_comes_back(): void
    {
        $this->setFeature($this->company, Feature::Aggregator);
        $this->save();

        $ids = Tenancy::runFor($this->company, function () {
            $baghdad = $this->baghdad();
            $basra = Governorate::where('code', 'BSR')->firstOrFail();
            AggregatorLink::query()->sole()->forceFill(['catalog' => [
                'cities' => ['1' => 'بغداد', '5' => 'البصرة'],
                'regions' => ['1' => ['40' => 'ابو غريب', '41' => 'الكرادة', '42' => 'المنصور'], '5' => ['77' => 'العشار']],
            ]])->save();
            AggregatorPlace::create(['kind' => 'governorate', 'local_id' => $baghdad->id, 'remote_id' => '1']);
            $make = fn ($name) => City::create(['governorate_id' => $baghdad->id, 'company_id' => Tenancy::id(), 'name_ar' => $name, 'is_active' => true])->id;
            [$ghraib, $karada, $mansour] = [$make('أبو غريب'), $make('كرادة مريم'), $make('المنصور الداوودي')];
            // ربطها موظّفٌ بيده: لا يغيّرها الملف
            AggregatorPlace::create(['kind' => 'city', 'local_id' => $mansour, 'remote_id' => '42', 'method' => 'manual']);

            return ['ghraib' => $ghraib, 'karada' => $karada, 'mansour' => $mansour,
                'ashar' => $this->area('العشار', $basra), 'baghdad' => $baghdad->id, 'basra' => $basra->id];
        });

        // الملف: مدنهم ومناطقهم ومناطقنا بربطها، بلا أسرار
        $export = $this->actingAs($this->owner)->get($this->url('/areas/export'))->assertOk();
        $data = json_decode($export->streamedContent(), true);
        $baghdad = collect($data['governorates'])->firstWhere('id', $ids['baghdad']);
        $this->assertSame('1', (string) $baghdad['their_city_id']);
        $this->assertSame('ابو غريب', $baghdad['their_regions']['40']);
        $this->assertSame(['42', 'manual'], [(string) collect($baghdad['areas'])->firstWhere('id', $ids['mansour'])['their_region_id'], 'manual']);
        $this->assertStringNotContainsString('pass-123', $export->streamedContent());

        $file = UploadedFile::fake()->createWithContent('links.json', json_encode([
            'governorates' => [$ids['basra'] => '5', '999999' => '5'],
            'areas' => [
                $ids['ghraib'] => '40',
                $ids['karada'] => '77',    // منطقةٌ من مدينةٍ أخرى: تُرفض
                $ids['mansour'] => '41',   // ربطها موظّف: تبقى
                $ids['ashar'] => '77',     // محافظتها رُبطت في الملف نفسه
                '999999' => '40',          // ليست من مناطقنا
            ],
        ]));

        $this->actingAs($this->owner)->post($this->url('/areas/import'), ['file' => $file])->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 محافظة و2 منطقة') && str_contains($m, '1 ربطها موظّفٌ') && str_contains($m, '3 لم تُقبل'));

        $places = Tenancy::runFor($this->company, fn () => AggregatorPlace::query()->get()->groupBy('kind')->map->keyBy('local_id'));
        $this->assertSame(['40', 'file'], [$places['city'][$ids['ghraib']]->remote_id, $places['city'][$ids['ghraib']]->method]);
        $this->assertSame('77', $places['city'][$ids['ashar']]->remote_id);
        $this->assertSame('42', $places['city'][$ids['mansour']]->remote_id);
        $this->assertFalse(isset($places['city'][$ids['karada']]));
        $this->assertSame('5', $places['governorate'][$ids['basra']]->remote_id);
        $this->assertTrue(Tenancy::runFor($this->company, fn () => AuditLog::where('action', 'aggregator_areas_import')->exists()));

        // ملفٌ ليس ملف ربط يُرفض بسببه
        $bad = UploadedFile::fake()->createWithContent('x.json', 'not json');
        $this->actingAs($this->owner)->post($this->url('/areas/import'), ['file' => $bad])->assertSessionHasErrors('areas');
    }

    public function test_the_areas_pages_stay_light_with_every_governorate_and_thousands_of_areas(): void
    {
        $this->setFeature($this->company, Feature::Aggregator);

        // كل المحافظات مربوطة، ولكلٍّ مناطقها كاملةً عندهم — كما بعد «اربط بالاسم»: كانت الصفحة الواحدة تُبنى بملايين الخيارات فتنهار
        Tenancy::runFor($this->company, function () {
            $cities = $regions = [];
            foreach (Governorate::all() as $governorate) {
                $cities[(string) $governorate->id] = $governorate->name_ar;
                AggregatorPlace::create(['kind' => 'governorate', 'local_id' => $governorate->id, 'remote_id' => (string) $governorate->id]);
                $regions[(string) $governorate->id] = City::where('governorate_id', $governorate->id)->pluck('name_ar', 'id')
                    ->mapWithKeys(fn ($name, $id) => ['r'.$id => $name])->all();
            }
            AggregatorLink::query()->firstOrCreate([], ['base_url' => self::BASE])->forceFill(['catalog' => ['cities' => $cities, 'regions' => $regions]])->save();
        });

        $index = $this->actingAs($this->owner)->get($this->url('/areas'))->assertOk();
        $this->assertLessThan(500_000, strlen($index->getContent()));

        // أكبر محافظة: صفحةٌ بـ ١٥ صفّاً، لا بكل مناطقها
        $biggest = Tenancy::runFor($this->company, fn () => City::query()->selectRaw('governorate_id, count(*) as n')
            ->groupBy('governorate_id')->orderByDesc('n')->first());
        $page = $this->actingAs($this->owner)->get($this->url('/areas/'.$biggest->governorate_id))->assertOk();
        $this->assertLessThan(2_000_000, strlen($page->getContent()));
        $this->assertSame(15, substr_count($page->getContent(), 'name="city['));

        // البحث والصفحة الثانية
        $this->actingAs($this->owner)->get($this->url('/areas/'.$biggest->governorate_id.'?page=2'))->assertOk();
        $first = Tenancy::runFor($this->company, fn () => City::where('governorate_id', $biggest->governorate_id)->orderBy('name_ar')->value('name_ar'));
        $this->actingAs($this->owner)->get($this->url('/areas/'.$biggest->governorate_id.'?q='.urlencode($first)))->assertOk()->assertSee($first);

        // محافظةٌ غير مربوطة: تُطلب مدينتها أوّلاً، وما ليس من مناطق الشركة لا يُربط ولو أُرسل رقمه
        Tenancy::runFor($this->company, fn () => AggregatorPlace::where('kind', 'governorate')->where('local_id', $biggest->governorate_id)->delete());
        $this->actingAs($this->owner)->get($this->url('/areas/'.$biggest->governorate_id))->assertOk()->assertSee('اربط المحافظة بمدينتها');
        $this->actingAs($this->owner)->put($this->url('/areas'), ['city' => [9_999_999 => 'r1']])->assertSessionHasNoErrors();
        $this->assertFalse(Tenancy::runFor($this->company, fn () => AggregatorPlace::where('kind', 'city')->where('local_id', 9_999_999)->exists()));
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
