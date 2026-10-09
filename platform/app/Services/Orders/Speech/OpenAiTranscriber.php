<?php

namespace App\Services\Orders\Speech;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * gpt-4o-transcribe من OpenAI: يسمع العربية، ويُعطى ما يُعينه (طلب توصيلٍ عراقي) فيُحسن الأسماء والأرقام.
 */
final class OpenAiTranscriber implements Transcriber
{
    public function __construct(
        private readonly string $key,
        private readonly string $model = 'gpt-4o-transcribe',
        private readonly float $timeout = 40,
    ) {}

    public function transcribe(string $path, string $mime, string $hint): string
    {
        try {
            $response = Http::withToken($this->key)
                ->timeout($this->timeout)
                ->attach('file', (string) file_get_contents($path), 'order.'.Recording::extension($mime), ['Content-Type' => $mime])
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model'           => $this->model,
                    'language'        => 'ar',
                    'prompt'          => $hint,
                    'response_format' => 'json',
                ]);
        } catch (ConnectionException $e) {
            throw new UnheardSpeech('connection', previous: $e);
        }

        if (! $response->successful() || ! is_string($response->json('text'))) {
            throw new UnheardSpeech('status '.$response->status());
        }

        return trim($response->json('text'));
    }
}
