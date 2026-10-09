<?php

namespace App\Http\Controllers;

use App\Services\Orders\AiOrderReader;
use App\Services\Orders\OrderReader;
use App\Services\Orders\Speech\Recording;
use App\Services\Orders\Speech\SpeechToText;
use App\Services\Orders\Speech\UnheardSpeech;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «تكلّم» بتسجيلٍ لا ينقطع (docs/plan/40): التاجر يسجّل الطلب حتى يضغط «أوقف»، فيصير التسجيل
 * نصّاً هنا، ثم يُقرأ الطلب منه — بالذكاء الاصطناعي، أو بقارئ الكلام المحلّي.
 *
 * يُرجع ما يملأ الحقول والنصّ المسموع ولا يحفظ شيئاً: التسجيل يُرمى مع الطلب.
 */
class OrderListeningController extends Controller
{
    public function __invoke(Request $request, SpeechToText $speech, AiOrderReader $ai, OrderReader $reader): JsonResponse
    {
        $request->validate([
            // دقيقتان من الكلام أقلّ من ثلاثة ميغابايت؛ والحدّ تحت حدّ PHP للرفع
            'audio' => ['required', 'file', 'max:7168', 'mimetypes:'.implode(',', Recording::TYPES)],
        ], [
            'audio.required'  => 'لم يصل التسجيل — اضغط «تكلّم» وأعد.',
            'audio.uploaded'  => 'تعذّر رفع التسجيل — أعد.',
            'audio.max'       => 'التسجيل أطول من اللازم — قل الطلب وحده.',
            'audio.mimetypes' => 'التسجيل بصيغةٍ لا تُقرأ — أعد من المتصفّح نفسه.',
        ]);

        if (! $speech->available()) {
            return response()->json(['message' => 'السماع على الخادم غير مفعّل بعد.'], 422);
        }

        $audio = $request->file('audio');

        try {
            $text = $speech->text($audio->getRealPath(), (string) $audio->getMimeType());
        } catch (UnheardSpeech) {
            return response()->json(['message' => 'تعذّر سماع التسجيل — أعد، أو اكتب الطلب في الخانة.'], 422);
        }

        if (mb_strlen(trim($text)) < 2) {
            return response()->json(['message' => 'لم يُسمع كلامٌ في التسجيل — اقترب من المايك وأعد.'], 422);
        }

        return response()->json(($ai->fromText($text, spoken: true) ?? $reader->fromSpeech($text)) + ['transcript' => $text]);
    }
}
