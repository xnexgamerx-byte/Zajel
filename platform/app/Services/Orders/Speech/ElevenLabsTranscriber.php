<?php

namespace App\Services\Orders\Speech;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Scribe من ElevenLabs: من أدقّ ما يسمع العربية ولهجاتها، والتسجيل كلّه بطلبٍ واحد.
 */
final class ElevenLabsTranscriber implements Transcriber
{
    public function __construct(
        private readonly string $key,
        private readonly string $model = 'scribe_v1',
        private readonly float $timeout = 40,
    ) {}

    public function transcribe(string $path, string $mime, string $hint): string
    {
        try {
            $response = Http::withHeaders(['xi-api-key' => $this->key])
                ->timeout($this->timeout)
                ->attach('file', (string) file_get_contents($path), 'order.'.Recording::extension($mime), ['Content-Type' => $mime])
                ->post('https://api.elevenlabs.io/v1/speech-to-text', [
                    'model_id'         => $this->model,
                    'language_code'    => 'ara',
                    'tag_audio_events' => 'false',
                    'diarize'          => 'false',
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
