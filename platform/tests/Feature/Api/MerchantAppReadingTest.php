<?php

namespace Tests\Feature\Api;

use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\Company;
use App\Models\Governorate;
use App\Models\User;
use App\Services\Orders\Speech\Transcriber;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * «إنشاء بالذكاء الاصطناعي» و«إنشاء بالتسجيل الصوتي» في تطبيق التاجر (docs/plan/55): قارئ
 * البوابة نفسه برمز التطبيق — يملأ النموذج ولا يحفظ شيئاً.
 */
class MerchantAppReadingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $merchant = $this->makeMerchant($this->company);
        $this->user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $merchant->id, 'is_active' => true,
        ]));
    }

    private function api(string $path): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/api/v1'.$path;
    }

    private function headers(): array
    {
        $token = $this->postJson($this->api('/login'), [
            'username' => $this->user->username, 'password' => 'password', 'app' => 'merchant',
        ])->assertOk()->json('token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function city(string $name, string $code): int
    {
        return (int) City::where('governorate_id', Governorate::where('code', $code)->value('id'))->where('name_ar', $name)->value('id');
    }

    /** تسجيلٌ كما يرفعه التطبيق: m4a (AAC) */
    private function recording(): UploadedFile
    {
        $header = "\x00\x00\x00\x20ftypM4A \x00\x00\x00\x00M4A mp42isom\x00\x00\x00\x00";

        return UploadedFile::fake()->createWithContent('order.m4a', $header.str_repeat("\0", 4000));
    }

    public function test_the_cards_follow_the_feature_and_the_speech_key(): void
    {
        $headers = $this->headers();

        $this->getJson($this->api('/merchant/shipments/form'), $headers)->assertOk()
            ->assertJsonPath('reading', false)->assertJsonPath('listening', false);
        $this->postJson($this->api('/merchant/shipments/read'), ['text' => 'علي 07712345678'], $headers)->assertForbidden();

        $this->setFeature($this->company, Feature::OrderReading);
        $this->getJson($this->api('/merchant/shipments/form'), $headers)->assertOk()
            ->assertJsonPath('reading', true)->assertJsonPath('listening', false);
        $this->post($this->api('/merchant/shipments/listen'), ['audio' => $this->recording()], $headers)
            ->assertStatus(422)->assertJsonPath('message', 'السماع على الخادم غير مفعّل بعد.');

        $this->app->instance(Transcriber::class, new class implements Transcriber
        {
            public function transcribe(string $path, string $mime, string $hint): string
            {
                return '';
            }
        });
        $this->getJson($this->api('/merchant/shipments/form'), $headers)->assertJsonPath('listening', true);
    }

    public function test_a_pasted_message_fills_the_form_with_the_area_names(): void
    {
        $this->setFeature($this->company, Feature::OrderReading);

        $this->postJson($this->api('/merchant/shipments/read'), [
            'text' => "الاسم: علي حسين\nرقمي 07712345678\nبغداد الكرادة قرب ساحة كهرمانة\nالسعر 25 الف",
        ], $this->headers())->assertOk()
            ->assertJsonPath('fields.recipient_name', 'علي حسين')
            ->assertJsonPath('fields.recipient_phone', '07712345678')
            ->assertJsonPath('fields.cod_amount', 25_000)
            ->assertJsonPath('fields.city_id', $this->city('الكرادة', 'BGD'))
            ->assertJsonPath('found.governorate_id', 'بغداد')
            ->assertJsonPath('found.city_id', 'الكرادة');

        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_a_recording_becomes_the_order(): void
    {
        $this->setFeature($this->company, Feature::OrderReading);
        $this->app->instance(Transcriber::class, new class implements Transcriber
        {
            public function transcribe(string $path, string $mime, string $hint): string
            {
                return 'مصطفى عادل صفر سبعه سبعه اربعمية و عشرة سبعه سبعه ثلاث تساعات بغداد الكرادة قرب الجامع خمسة وعشرين الف';
            }
        });

        $this->post($this->api('/merchant/shipments/listen'), ['audio' => $this->recording()], $this->headers())->assertOk()
            ->assertJsonPath('fields.recipient_name', 'مصطفى عادل')
            ->assertJsonPath('fields.recipient_phone', '07741077999')
            ->assertJsonPath('fields.city_id', $this->city('الكرادة', 'BGD'))
            ->assertJsonPath('fields.cod_amount', 25_000)
            ->assertJsonStructure(['transcript']);
    }

    public function test_an_image_must_be_a_screenshot(): void
    {
        $this->setFeature($this->company, Feature::OrderReading);

        $this->post($this->api('/merchant/shipments/read'), ['image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')],
            $this->headers())->assertStatus(422)->assertJsonValidationErrors('image');
    }
}
