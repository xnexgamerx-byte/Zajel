<?php

namespace Tests\Feature\Shipments;

use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\Company;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Orders\Ai\ClaudeOrderModel;
use App\Services\Orders\Ai\OrderModel;
use App\Services\Orders\Ai\UnreadableByModel;
use App\Support\Tenancy\Tenancy;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * قراءة الطلب بالذكاء الاصطناعي (docs/plan/40): النموذج يقرأ الصورة والرسالة والكلام،
 * والمنطقة تُطابق بقوائم الشركة، وإن تعذّر فالقارئ المحلّي. ولا يخرج طلبٌ إلى الشبكة هنا.
 */
class AiOrderReadingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    /** النموذج البديل: ما سُئل، وما يُجيب */
    private FakeOrderModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->setFeature($this->company, Feature::OrderReading);
        $this->owner = $this->makeUser($this->company);

        config(['zajel.ai.key' => 'test-key']);
        $this->model = new FakeOrderModel;
        $this->app->instance(OrderModel::class, $this->model);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function governorate(string $code): int
    {
        return (int) Governorate::where('code', $code)->value('id');
    }

    private function city(string $name, string $code): int
    {
        return (int) City::where('governorate_id', $this->governorate($code))->where('name_ar', $name)->value('id');
    }

    /** @param array<string, mixed> $overrides */
    private function answer(array $overrides = []): array
    {
        return $overrides + [
            'is_order' => true, 'recipient_name' => 'علي حسين', 'recipient_phone' => '+964 770 123 4567',
            'recipient_phone_alt' => '', 'governorate' => 'بغداد', 'area' => 'الكرادة', 'landmark' => 'قرب ساحة كهرمانة',
            'cod_amount' => 25_000, 'pieces_count' => 2, 'notes' => 'اتصل قبل الوصول',
            'text' => "علي حسين\n07701234567\nبغداد الكرادة قرب ساحة كهرمانة\n25 الف",
        ];
    }

    public function test_a_pasted_message_is_read_by_the_model_and_matched_to_the_company_lists(): void
    {
        $this->model->answer = $this->answer();

        $this->actingAs($this->owner)->get($this->host().'/shipments/create')
            ->assertOk()->assertSee('اقرأ الطلب بالذكاء الاصطناعي');

        $this->actingAs($this->owner)->postJson($this->host().'/shipments/read', ['text' => 'هلا اريد اطلب… علي حسين 07701234567'])
            ->assertOk()
            ->assertJsonPath('engine', 'ai')
            ->assertJsonPath('fields', [
                'recipient_phone' => '07701234567',
                'cod_amount'      => 25_000,
                'pieces_count'    => 2,
                'governorate_id'  => $this->governorate('BGD'),
                'city_id'         => $this->city('الكرادة', 'BGD'),
                'landmark'        => 'قرب ساحة كهرمانة',
                'recipient_name'  => 'علي حسين',
                'notes'           => 'اتصل قبل الوصول',
            ])
            ->assertJsonPath('missing', [])
            ->assertJsonPath('found.cod_amount', '25,000 د.ع')
            ->assertJsonPath('lines.0', 'علي حسين');

        // التعليمات تُخزَّن، ومحافظات الشركة من قائمتها، والرسالة كما أُرسلت
        [$system, $content, $schema] = $this->model->calls[0];
        $this->assertSame(['type' => 'ephemeral'], $system[0]['cacheControl']);
        $this->assertStringContainsString('بغداد', $system[1]['text']);
        $this->assertStringStartsWith('رسالة الزبون:', $content[0]['text']);
        $this->assertContains('بغداد', $schema['properties']['governorate']['enum']);
        $this->assertFalse($schema['additionalProperties']);
    }

    public function test_the_merchants_words_are_sent_as_speech(): void
    {
        $this->model->answer = $this->answer(['cod_amount' => 150_500]);
        $merchant = $this->makeMerchant($this->company);
        $user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $merchant->id, 'is_active' => true,
        ]));

        $this->actingAs($user)->postJson($this->host().'/portal/shipments/read', [
            'text' => 'الاسم علي حسين الرقم صفر سبعة سبعة صفر … السعر مية وخمسين الف ونص', 'spoken' => 1,
        ])->assertOk()->assertJsonPath('fields.cod_amount', 150_500)->assertJsonPath('engine', 'ai');

        $this->assertStringStartsWith('كلام التاجر كما سمعه الهاتف:', $this->model->calls[0][1][0]['text']);
    }

    public function test_a_screenshot_goes_to_the_model_as_an_image(): void
    {
        $this->model->answer = $this->answer();

        $this->actingAs($this->owner)->post($this->host().'/shipments/read', [
            'image' => UploadedFile::fake()->createWithContent('chat.png', (string) file_get_contents(base_path('tests/Fixtures/orders/chat-light.png'))),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('fields.recipient_phone', '07701234567');

        $image = $this->model->calls[0][1][0];
        $this->assertSame('image', $image['type']);
        $this->assertSame('base64', $image['source']['type']);
        $this->assertContains($image['source']['mediaType'], ['image/png', 'image/jpeg']);
        $this->assertNotFalse(base64_decode($image['source']['data'], true));
    }

    public function test_what_is_not_in_the_system_is_not_filled(): void
    {
        $this->model->answer = $this->answer([
            'recipient_phone' => '12345', 'cod_amount' => 99, 'pieces_count' => 900, 'area' => 'منطقة لا وجود لها أبداً',
        ]);

        $response = $this->actingAs($this->owner)->postJson($this->host().'/shipments/read', ['text' => 'طلب'])->assertOk();

        $fields = $response->json('fields');
        $this->assertArrayNotHasKey('recipient_phone', $fields);
        $this->assertArrayNotHasKey('cod_amount', $fields);
        $this->assertArrayNotHasKey('pieces_count', $fields);
        $this->assertArrayNotHasKey('city_id', $fields);
        $this->assertSame($this->governorate('BGD'), $fields['governorate_id']);
        $this->assertSame(['رقم الهاتف', 'المنطقة', 'المبلغ'], $response->json('missing'));
        $this->assertStringContainsString('ليست في قائمة المناطق', implode(' ', $response->json('warnings')));
    }

    public function test_when_the_model_fails_the_local_reader_answers(): void
    {
        $this->model->failure = new UnreadableByModel('stop: refusal');

        $this->actingAs($this->owner)->postJson($this->host().'/shipments/read', ['text' => "علي حسين\n07712345678\nبغداد الكرادة\n25 الف"])
            ->assertOk()
            ->assertJsonMissingPath('engine')
            ->assertJsonPath('fields.recipient_phone', '07712345678')
            ->assertJsonPath('fields.city_id', $this->city('الكرادة', 'BGD'))
            ->assertJsonPath('fields.cod_amount', 25_000);
    }

    public function test_without_a_key_the_model_is_never_asked(): void
    {
        config(['zajel.ai.key' => null]);

        $this->actingAs($this->owner)->get($this->host().'/shipments/create')
            ->assertOk()->assertDontSee('اقرأ الطلب بالذكاء الاصطناعي');
        $this->actingAs($this->owner)->postJson($this->host().'/shipments/read', ['text' => "07712345678\nبغداد الكرادة\n25 الف"])
            ->assertOk()->assertJsonPath('fields.cod_amount', 25_000);

        $this->assertSame([], $this->model->calls);
    }

    public function test_the_claude_request_and_its_answer(): void
    {
        $sent = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => json_encode(['recipient_phone' => '07701234567', 'cod_amount' => 25000])]],
            'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
        ]))]));
        $stack->push(Middleware::history($sent));

        $model = new ClaudeOrderModel('test-key', 'claude-opus-5-5', 5, new Guzzle(['handler' => $stack]));
        $answer = $model->extract(
            [['type' => 'text', 'text' => 'تعليمات', 'cacheControl' => ['type' => 'ephemeral']]],
            [['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/png', 'data' => 'aGVsbG8=']]],
            ['type' => 'object', 'properties' => ['recipient_phone' => ['type' => 'string']], 'required' => ['recipient_phone'], 'additionalProperties' => false],
        );

        $this->assertSame(['recipient_phone' => '07701234567', 'cod_amount' => 25000], $answer);

        // على الشبكة بأسماء الواجهة نفسها
        $body = json_decode((string) $sent[0]['request']->getBody(), true);
        $this->assertSame('https://api.anthropic.com/v1/messages', (string) $sent[0]['request']->getUri());
        $this->assertSame('test-key', $sent[0]['request']->getHeaderLine('x-api-key'));
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame('low', $body['output_config']['effort']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
        $this->assertSame('image/png', $body['messages'][0]['content'][0]['source']['media_type']);
    }

    public function test_a_refusal_is_not_an_answer(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [], 'stop_reason' => 'refusal', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
        ]))]));

        $this->expectException(UnreadableByModel::class);
        (new ClaudeOrderModel('test-key', 'claude-opus-5-5', 5, new Guzzle(['handler' => $stack])))
            ->extract([['type' => 'text', 'text' => 'x']], [['type' => 'text', 'text' => 'y']], ['type' => 'object']);
    }
}

/** نموذجٌ ثابت للاختبار: يحفظ ما سُئل ويُرجع جوابه، أو يرمي خطأه */
class FakeOrderModel implements OrderModel
{
    /** @var list<array{0: array, 1: array, 2: array}> */
    public array $calls = [];

    public array $answer = [];

    public ?\Throwable $failure = null;

    public function extract(array $system, array $content, array $schema): array
    {
        $this->calls[] = [$system, $content, $schema];

        if ($this->failure) {
            throw $this->failure;
        }

        return $this->answer;
    }
}
