<?php

namespace App\Services\Orders\Speech;

use App\Models\Governorate;
use Illuminate\Support\Facades\Log;

/**
 * السماع على الخادم (docs/plan/40): التاجر يسجّل حتى يضغط «أوقف» — بلا انقطاعٍ عند السكتة
 * ولا صوتٍ عند كلّ بداية — ويُرسَل التسجيل هنا فيصير نصّاً، ثم يُقرأ الطلب منه.
 *
 * بلا مفتاح محرّكٍ لا سماع هنا (available() = false)، فيسمع المتصفّح كما كان.
 */
final class SpeechToText
{
    public function __construct(private readonly ?Transcriber $engine) {}

    public function available(): bool
    {
        return $this->engine !== null;
    }

    /** @throws UnheardSpeech */
    public function text(string $path, string $mime): string
    {
        if ($this->engine === null) {
            throw new UnheardSpeech('no engine');
        }

        $hint = 'طلب توصيل في العراق بلهجةٍ عراقية: اسم الزبون، ورقم هاتفه (صفر سبعة…)، والمحافظة والمنطقة وأقرب نقطة، '
            .'والمبلغ بالألف. المحافظات: '.Governorate::offered()->pluck('governorates.name_ar')->implode('، ').'.';

        try {
            return $this->engine->transcribe($path, $mime, $hint);
        } catch (UnheardSpeech $e) {
            // لا شيء من التسجيل في السجلّ: سبب الخطأ وحده
            Log::warning('order speech was not transcribed', ['engine' => $this->engine::class, 'reason' => $e->getMessage()]);

            throw $e;
        }
    }
}
