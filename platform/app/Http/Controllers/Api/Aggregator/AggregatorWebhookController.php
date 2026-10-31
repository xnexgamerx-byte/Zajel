<?php

namespace App\Http\Controllers\Api\Aggregator;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\AggregatorLink;
use App\Services\Aggregator\AggregatorInbox;
use App\Support\FeatureGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * حيث يرسل نظام الوسيط تحديثات طلباتنا (docs/plan/104). الشركة من العنوان (IdentifyTenant)، والطلب
 * يحمل مفتاحها في الترويسة التي اختارتها. يُردّ بسرعة (مهلتهم ٣ ثوانٍ، ثم يعيدون أربع مرّات):
 * يُحفظ التحديث ويُطبَّق، وما لا شحنة له أو تعذّر يُحفظ ويُرى في شاشة الربط ولا يُرفض.
 */
class AggregatorWebhookController extends Controller
{
    /** أكثر ما يُقبل في طلبٍ واحد إن أرسلوا قائمة */
    private const MAX_ITEMS = 50;

    public function __invoke(Request $request, AggregatorInbox $inbox): JsonResponse
    {
        $link = FeatureGate::enabled(Feature::Aggregator) ? AggregatorLink::query()->first() : null;

        if ($link === null || $link->courier_id === null) {
            return $this->reply(false, 'not found', 404);
        }

        if (! $link->webhookAccepts($request->header($link->webhook_header))) {
            return $this->reply(false, 'unauthorized', 401);
        }

        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return $this->reply(false, 'empty body', 422);
        }

        foreach (array_slice(array_is_list($payload) ? $payload : [$payload], 0, self::MAX_ITEMS) as $item) {
            if (is_array($item)) {
                $inbox->receive($link, $item);
            }
        }

        return $this->reply(true, 'ok');
    }

    /** بصيغة ردودهم نفسها */
    private function reply(bool $ok, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['status' => $ok, 'errNum' => $ok ? 'S000' : (string) $status, 'msg' => $message], $status);
    }
}
