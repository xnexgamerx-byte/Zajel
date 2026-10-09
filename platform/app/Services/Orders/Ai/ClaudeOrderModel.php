<?php

namespace App\Services\Orders\Ai;

use Anthropic\Client;
use Anthropic\RequestOptions;
use GuzzleHttp\Client as Guzzle;
use Psr\Http\Client\ClientInterface;

/**
 * Claude يقرأ الطلب (docs/plan/40): مخرجاتٌ منظّمة بمخطّطٍ ثابت، وجهدٌ منخفض — قراءةُ طلبٍ
 * لا تحتاج تفكيراً طويلاً، والتاجر ينتظر. وللطلب مهلةٌ ومحاولةٌ ثانية واحدة، ثم يُقرأ محلّياً.
 */
final class ClaudeOrderModel implements OrderModel
{
    private ?Client $client = null;

    /** @param ?ClientInterface $transporter ناقلٌ بعينه — للاختبار؛ وإلّا Guzzle بمهلة */
    public function __construct(
        private readonly string $key,
        private readonly string $model,
        private readonly float $timeout = 40,
        private readonly ?ClientInterface $transporter = null,
    ) {}

    public function extract(array $system, array $content, array $schema): array
    {
        $message = $this->client()->messages->create(
            maxTokens: 4000,
            messages: [['role' => 'user', 'content' => $content]],
            model: $this->model,
            system: $system,
            outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        );

        if ($message->stopReason !== 'end_turn') {
            throw new UnreadableByModel("stop: {$message->stopReason}");
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $answer = json_decode($block->text, true);

                if (is_array($answer)) {
                    return $answer;
                }
            }
        }

        throw new UnreadableByModel('no json');
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: $this->key,
            requestOptions: RequestOptions::with(
                timeout: $this->timeout,
                maxRetries: 1,
                // المهلة يفرضها ناقل الطلبات لا الحزمة: بلا هذا ينتظر التاجر دقائق إن تعثّر الاتصال
                transporter: $this->transporter ?? new Guzzle(['timeout' => $this->timeout, 'connect_timeout' => 5]),
            ),
        );
    }
}
