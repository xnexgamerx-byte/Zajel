<?php

namespace App\Http\Controllers;

use App\Services\Orders\AiOrderReader;
use App\Services\Orders\OrderReader;
use App\Services\Orders\ScreenshotText;
use App\Services\Orders\UnreadableImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «اقرأ الطلب من صورة أو رسالة» في نموذج الشحنة — للموظّف ولبوابة التاجر (docs/plan/34)،
 * وبالصوت: ما قاله التاجر نصّاً. ويقرأ ذلك كلّه الذكاء الاصطناعي إن كان له مفتاح، والقارئ
 * المحلّي إن لم يكن أو تعذّرت القراءة (docs/plan/40).
 *
 * يُرجع ما يملأ الحقول ولا يحفظ شيئاً: الصورة تُقرأ على الخادم وتُرمى مع الطلب.
 */
class OrderReadingController extends Controller
{
    public function __invoke(Request $request, OrderReader $reader, ScreenshotText $screenshots, AiOrderReader $ai): JsonResponse
    {
        // حدّ الصورة تحت حدّ PHP للرفع (docker/php.ini: 8M): تصل الرسالة العربية لا خطأ الرفع
        $data = $request->validate([
            'image' => ['nullable', 'required_without:text', 'file', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'text'  => ['nullable', 'required_without:image', 'string', 'max:5000'],
            // كلام التاجر كما سمعه المتصفّح أو مايك لوحة المفاتيح (docs/plan/40)
            'spoken' => ['nullable', 'boolean'],
        ], [
            'image.required_without' => 'اختر صورةً أو الصق نص الرسالة.',
            'text.required_without'  => 'اختر صورةً أو الصق نص الرسالة.',
            'image.uploaded'         => 'تعذّر رفع الصورة — أرسل لقطة الشاشة نفسها.',
            'image.mimes'            => 'الصورة JPG أو PNG أو WEBP — لقطة شاشة الهاتف.',
            'image.max'              => 'الصورة أكبر من ٦ ميغابايت — أرسل لقطة الشاشة نفسها.',
            'text.max'               => 'الرسالة أطول من ٥٠٠٠ حرف — الصق رسالة الطلب وحدها.',
        ]);

        // بالذكاء الاصطناعي أوّلاً (docs/plan/40)، وإن لم يكن أو تعذّر فالقارئ المحلّي كما كان
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            if ($reading = $ai->fromImage($image->getRealPath(), (string) $image->getMimeType())) {
                return response()->json($reading);
            }

            if (! $screenshots->available()) {
                return response()->json(['message' => 'قراءة الصور غير مثبّتة على هذا الخادم بعد — الصق نص الرسالة.'], 422);
            }

            try {
                return response()->json($reader->fromImage($request->file('image')->getRealPath()));
            } catch (UnreadableImage $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $spoken = $request->boolean('spoken');

        return response()->json($ai->fromText($data['text'], $spoken)
            ?? ($spoken ? $reader->fromSpeech($data['text']) : $reader->fromText($data['text'])));
    }
}
