<?php

namespace Tests\Feature\Shipments;

use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\Company;
use App\Models\Governorate;
use App\Models\User;
use App\Services\Orders\Ai\OrderModel;
use App\Services\Orders\Speech\ElevenLabsTranscriber;
use App\Services\Orders\Speech\OpenAiTranscriber;
use App\Services\Orders\Speech\SpeechToText;
use App\Services\Orders\Speech\Transcriber;
use App\Services\Orders\Speech\UnheardSpeech;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «تكلّم» بتسجيلٍ لا ينقطع حتى «أوقف» (docs/plan/40): التسجيل يصير نصّاً على الخادم، ثم
 * يُقرأ الطلب منه. ولا يخرج تسجيلٌ إلى الشبكة هنا.
 */
class OrderListeningTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $merchantUser;

    private FakeTranscriber $ears;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->setFeature($this->company, Feature::OrderReading);
        $merchant = $this->makeMerchant($this->company);
        $this->merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $merchant->id, 'is_active' => true,
        ]));

        $this->ears = new FakeTranscriber;
        $this->app->instance(Transcriber::class, $this->ears);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function city(string $name, string $code): int
    {
        return (int) City::where('governorate_id', Governorate::where('code', $code)->value('id'))->where('name_ar', $name)->value('id');
    }

    /** تسجيل webm كما يرفعه كروم في أندرويد: رأس EBML ثم صوت */
    private function recording(): UploadedFile
    {
        $header = "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02";

        return UploadedFile::fake()->createWithContent('order.webm', $header.str_repeat("\0", 4000));
    }

    private function listen(?UploadedFile $audio = null)
    {
        return $this->actingAs($this->merchantUser)
            ->post($this->host().'/portal/shipments/listen', ['audio' => $audio ?? $this->recording()], ['Accept' => 'application/json']);
    }

    public function test_a_recording_until_stop_becomes_the_order(): void
    {
        $this->ears->text = 'مصطفى عادل صفر سبعه سبعه اربعمية و عشرة سبعه سبعه ثلاث تساعات بغداد الكرادة قرب الجامع خمسة وعشرين الف';

        // الزرّ يعرف أنّ للخادم سماعاً: يسجّل حتى «أوقف»
        $this->actingAs($this->merchantUser)->get($this->host().'/portal/shipments/create')
            ->assertOk()->assertSee('data-listen-url="'.$this->host().'/portal/shipments/listen"', false);

        $this->listen()->assertOk()
            ->assertJsonPath('transcript', $this->ears->text)
            ->assertJsonPath('fields.recipient_name', 'مصطفى عادل')
            ->assertJsonPath('fields.recipient_phone', '07741077999')
            ->assertJsonPath('fields.city_id', $this->city('الكرادة', 'BGD'))
            ->assertJsonPath('fields.cod_amount', 25_000);

        [$mime, $hint] = $this->ears->calls[0];
        $this->assertSame('video/webm', $mime);
        $this->assertStringContainsString('بغداد', $hint);
    }

    public function test_with_the_ai_the_transcript_is_read_as_speech(): void
    {
        config(['zajel.ai.key' => 'test-key']);
        $model = new FakeOrderModel;
        $model->answer = ['is_order' => true, 'recipient_name' => 'مصطفى عادل', 'recipient_phone' => '07741077999', 'recipient_phone_alt' => '',
            'governorate' => 'بغداد', 'area' => 'الكرادة', 'landmark' => 'قرب الجامع', 'cod_amount' => 25_000, 'pieces_count' => 0,
            'notes' => '', 'text' => ''];
        $this->app->instance(OrderModel::class, $model);
        $this->ears->text = 'مصطفى عادل صفر سبعه سبعه اربعمية و عشرة سبعه سبعه ثلاث تساعات';

        $this->listen()->assertOk()->assertJsonPath('engine', 'ai')->assertJsonPath('fields.recipient_phone', '07741077999');

        $this->assertSame('كلام التاجر كما سمعه الهاتف:'."\n\n".$this->ears->text, $model->calls[0][1][0]['text']);
    }

    public function test_what_cannot_be_heard_says_so(): void
    {
        $this->ears->failure = new UnheardSpeech('status 500');
        $this->listen()->assertStatus(422)->assertJsonPath('message', 'تعذّر سماع التسجيل — أعد، أو اكتب الطلب في الخانة.');

        $this->ears->failure = null;
        $this->ears->text = '';
        $this->listen()->assertStatus(422)->assertJsonPath('message', 'لم يُسمع كلامٌ في التسجيل — اقترب من المايك وأعد.');

        // وما ليس تسجيلاً يُردّ قبل أن يُسمع
        $this->listen(UploadedFile::fake()->createWithContent('x.png', (string) file_get_contents(base_path('tests/Fixtures/orders/chat-light.png'))))
            ->assertStatus(422)->assertJsonValidationErrors('audio');
        $this->assertCount(2, $this->ears->calls);
    }

    public function test_without_a_speech_key_the_browser_listens(): void
    {
        $this->app->forgetInstance(Transcriber::class);
        $this->app->offsetUnset(Transcriber::class);

        $this->assertFalse(app(SpeechToText::class)->available());
        $this->actingAs($this->merchantUser)->get($this->host().'/portal/shipments/create')
            ->assertOk()->assertSee('data-order-talk', false)->assertDontSee('data-listen-url', false);
        $this->listen()->assertStatus(422)->assertJsonPath('message', 'السماع على الخادم غير مفعّل بعد.');
    }

    public function test_elevenlabs_hears_the_recording(): void
    {
        Http::fake(['api.elevenlabs.io/*' => Http::response(['language_code' => 'ara', 'text' => ' الاسم علي '])]);
        $recording = $this->recording();
        $path = $recording->getRealPath();

        $text = (new ElevenLabsTranscriber('el-key', 'scribe_v1', 5))->transcribe($path, 'audio/webm', 'تلميح');

        $this->assertSame('الاسم علي', $text);
        Http::assertSent(function (SentRequest $request) {
            $parts = collect($request->data())->keyBy('name');

            return $request->url() === 'https://api.elevenlabs.io/v1/speech-to-text'
                && $request->hasHeader('xi-api-key', 'el-key')
                && $parts['model_id']['contents'] === 'scribe_v1'
                && $parts['language_code']['contents'] === 'ara'
                && $parts['file']['filename'] === 'order.webm';
        });
    }

    public function test_openai_hears_the_recording_with_a_hint(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['text' => 'صفر سبعة سبعة'])]);
        $recording = $this->recording();
        $path = $recording->getRealPath();

        $this->assertSame('صفر سبعة سبعة', (new OpenAiTranscriber('oa-key', 'gpt-4o-transcribe', 5))->transcribe($path, 'audio/mp4', 'طلب توصيل'));

        Http::assertSent(function (SentRequest $request) {
            $parts = collect($request->data())->keyBy('name');

            return $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
                && $request->hasHeader('Authorization', 'Bearer oa-key')
                && $parts['model']['contents'] === 'gpt-4o-transcribe'
                && $parts['language']['contents'] === 'ar'
                && $parts['prompt']['contents'] === 'طلب توصيل'
                && $parts['file']['filename'] === 'order.m4a';
        });
    }

    public function test_a_failing_service_is_unheard_speech(): void
    {
        Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => 'quota'], 429)]);

        $this->expectException(UnheardSpeech::class);
        $recording = $this->recording();
        (new ElevenLabsTranscriber('el-key'))->transcribe($recording->getRealPath(), 'audio/webm', '');
    }

    public function test_the_engine_follows_the_key(): void
    {
        $this->app->offsetUnset(Transcriber::class);
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['text' => 'من ElevenLabs']),
            'api.openai.com/*'    => Http::response(['text' => 'من OpenAI']),
        ]);
        $recording = $this->recording();
        $path = $recording->getRealPath();
        $hear = fn () => Tenancy::runFor($this->company, fn () => app(SpeechToText::class)->text($path, 'audio/webm'));

        config(['zajel.speech.openai.key' => 'oa-key']);
        $this->assertSame('من OpenAI', $hear());

        // ومفتاح ElevenLabs يُقدَّم إن وُجد المفتاحان
        config(['zajel.speech.elevenlabs.key' => 'el-key']);
        $this->assertSame('من ElevenLabs', $hear());

        // إلّا إن سُمّي المحرّك
        config(['zajel.speech.provider' => 'openai']);
        $this->assertSame('من OpenAI', $hear());
    }
}

/** محرّك سماعٍ ثابت للاختبار */
class FakeTranscriber implements Transcriber
{
    /** @var list<array{0: string, 1: string}> */
    public array $calls = [];

    public string $text = '';

    public ?\Throwable $failure = null;

    public function transcribe(string $path, string $mime, string $hint): string
    {
        $this->calls[] = [$mime, $hint];

        if ($this->failure) {
            throw $this->failure;
        }

        return $this->text;
    }
}
