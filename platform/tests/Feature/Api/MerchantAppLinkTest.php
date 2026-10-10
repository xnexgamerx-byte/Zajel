<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * «هذا غير مربوط بالنظام؟» (docs/plan/59): التطبيق والموقع نظامٌ واحد. ما يضعه الموظّف في
 * الموقع يصل التطبيق، وما يرسله التاجر من التطبيق يصل الموظّف — بالصور.
 */
class MerchantAppLinkTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $user;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // في ساعات مراسلة الشركة (docs/plan/39)
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00', 'Asia/Baghdad'));
        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
        $this->user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password', 'role' => UserRole::Merchant,
            'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function api(string $path): string
    {
        return $this->host().'/api/v1'.$path;
    }

    private function token(): array
    {
        $token = $this->postJson($this->api('/login'), [
            'username' => $this->user->username, 'password' => 'password', 'app' => 'merchant',
        ])->assertOk()->json('token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /** رابطٌ من النظام يُطلب كما يطلبه الهاتف: بمساره على عنوان الشركة */
    private function fetch(string $url, array $headers)
    {
        $path = parse_url($url, PHP_URL_PATH).(($q = parse_url($url, PHP_URL_QUERY)) ? '?'.$q : '');

        return $this->get($this->host().$path, $headers);
    }

    public function test_an_ad_uploaded_on_the_site_shows_on_the_app_home_with_its_image(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/app-ads', [
            'title' => 'أسعار الشتاء', 'audience' => 'merchants', 'sort_order' => 1,
            'image' => UploadedFile::fake()->image('ad.jpg', 800, 400),
        ])->assertSessionHasNoErrors();
        auth()->forgetGuards();

        $headers = $this->token();
        $banners = $this->getJson($this->api('/merchant/home'), $headers)->assertOk()->json('banners');

        $this->assertSame(['أسعار الشتاء'], array_column($banners, 'title'));
        $this->fetch($banners[0]['image'], $headers)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_message_from_the_site_reaches_the_app_and_the_merchant_reply_with_a_photo_reaches_the_site(): void
    {
        // «راسل تاجراً» من الموقع
        $this->actingAs($this->owner)->post($this->host().'/conversations', [
            'merchant_id' => $this->merchant->id, 'subject' => 'طرد الكرادة', 'body' => 'الزبون يطلب التأجيل',
        ])->assertSessionHasNoErrors();
        auth()->forgetGuards();
        $conversation = Tenancy::runFor($this->company, fn () => Conversation::latest('id')->firstOrFail());

        $headers = $this->token();
        // الجرس يعدّها، والإشعارات والدعم يعرضانها
        $this->assertSame(1, $this->getJson($this->api('/merchant/home'), $headers)->json('unread'));
        $this->getJson($this->api('/merchant/notifications'), $headers)->assertOk()->assertJsonFragment(['id' => $conversation->id]);
        $this->getJson($this->api('/merchant/support'), $headers)->assertOk()
            ->assertJsonFragment(['subject' => 'طرد الكرادة', 'unread' => true, 'staff' => true]);
        $this->getJson($this->api('/merchant/support/'.$conversation->id), $headers)->assertOk()
            ->assertJsonFragment(['body' => 'الزبون يطلب التأجيل']);

        // يردّ التاجر من التطبيق بصورة
        $this->post($this->api('/merchant/support/'.$conversation->id.'/reply'), [
            'body' => 'تمام، بعد يومين', 'attachment' => UploadedFile::fake()->image('receipt.jpg', 900, 1200),
        ], $headers)->assertCreated();

        // فيراها الموظّف في «المحادثات» بصورتها
        $page = $this->actingAs($this->owner)->get($this->host().'/conversations/'.$conversation->id)->assertOk();
        $page->assertSee('تمام، بعد يومين')->assertSee('receipt.jpg');
        $message = Tenancy::runFor($this->company, fn () => $conversation->messages()->reorder()->latest('id')->firstOrFail());
        $this->get($this->host().'/conversations/'.$conversation->id.'/files/'.$message->id)->assertOk();
    }

    public function test_a_message_started_in_the_app_shows_in_the_site_conversations(): void
    {
        $headers = $this->token();
        $this->post($this->api('/merchant/support'), [
            'subject' => 'وصل ناقص', 'body' => 'الوصل 123 ما وصل',
            'attachment' => UploadedFile::fake()->image('waybill.png', 600, 600),
        ], $headers)->assertCreated();

        $this->actingAs($this->owner)->get($this->host().'/conversations')->assertOk()
            ->assertSee('وصل ناقص')->assertSee($this->merchant->business_name);
    }

    public function test_the_merchant_photo_uploads_from_the_app_and_loads_back(): void
    {
        $headers = $this->token();
        $url = $this->post($this->api('/merchant/logo'), [
            'logo' => UploadedFile::fake()->image('me.jpg', 600, 600),
        ], $headers)->assertOk()->json('logo');

        $this->assertSame($url, $this->getJson($this->api('/merchant/home'), $headers)->json('logo'));
        $this->fetch($url, $headers)->assertOk();
    }
}
