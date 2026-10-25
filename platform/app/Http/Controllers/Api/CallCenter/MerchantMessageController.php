<?php

namespace App\Http\Controllers\Api\CallCenter;

use App\Http\Controllers\Controller;
use App\Support\MerchantMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «رسالتي الثابتة للتاجر» من التطبيق (docs/plan/83): نصّ الموظّفة نفسه الذي تكتبه في الموقع — الحقل
 * نفسه، فما تعدّله هنا تجده هناك. تُدرَج مملوءةً فوق حقل التاجر في كلّ شحنةٍ ومحادثة.
 */
class MerchantMessageController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::json($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:'.MerchantMessage::MAX],
            'reset' => ['nullable', 'boolean'],
        ], [], ['text' => 'الرسالة']);

        MerchantMessage::save($request->user(), $data['text'] ?? null, $request->boolean('reset'));

        return response()->json([
            'message' => $request->user()->merchant_message === null ? 'رسالتك هي القالب.' : 'حُفظت رسالتك للتاجر.',
            'data' => self::json($request),
        ]);
    }

    /** @return array{text: string, custom: bool, template: string, fields: list<array{key: string, meaning: string}>} */
    private static function json(Request $request): array
    {
        $user = $request->user()->refresh();

        return [
            'text' => MerchantMessage::templateOf($user),
            'custom' => filled($user->merchant_message),
            'template' => MerchantMessage::TEMPLATE,
            'fields' => collect(MerchantMessage::FIELDS)->map(fn ($meaning, $key) => ['key' => $key, 'meaning' => $meaning])->values()->all(),
        ];
    }
}
