<?php

namespace App\Services\Orders\Speech;

/**
 * محرّكٌ يحوّل تسجيل التاجر نصّاً (docs/plan/40): ElevenLabs أو OpenAI بحسب المفتاح.
 */
interface Transcriber
{
    /**
     * @param  string  $hint  ما يُعين السامع: طلب توصيلٍ عراقي، وأسماء المحافظات
     *
     * @throws UnheardSpeech ما لم يُسمع منه نصّ
     */
    public function transcribe(string $path, string $mime, string $hint): string;
}
