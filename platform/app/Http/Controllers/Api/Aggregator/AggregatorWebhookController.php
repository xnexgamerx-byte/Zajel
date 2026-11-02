<?php

namespace App\Http\Controllers\Api\Aggregator;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\AggregatorLink;
use App\Services\Aggregator\AggregatorInbox;
use App\Support\FeatureGate;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * حيث يرسل نظام الوسيط تحديثات طلباتنا (docs/plan/104). الشركة من العنوان (IdentifyTenant)، والطلب
 * يحمل مفتاحها في الترويسة التي اختارتها. يُردّ بسرعة (مهلتهم ٣ ثوانٍ، ثم يعيدون أربع مرّات): يُحفظ
 * التحديث ويُردّ، ثم يُطبَّق بعد الردّ (docs/plan/106). ما لا شحنة له أو تعذّر يُحفظ ويُرى في شاشة الربط ولا يُرفض.
 */
class AggregatorWebhookController extends Controller
{
    /** أكثر ما يُقبل في طلبٍ واحد إن أرسلوا قائمة */
    private const MAX_ITEMS = 50;

    public function __invoke(Request $request, AggregatorInbox $inbox, TenantContext $tenant): JsonResponse
    {
        $link = AggregatorLink::query()->first();

        if ($link === null || ! FeatureGate::enabled(Feature::Aggregator) || $link->courier_id === null) {
            $this->rejected($link, 'الميزة مغلقة أو لا مندوب وسيط مختار', 404);

            return $this->reply(false, 'not found', 404);
        }

        if ($link->webhook_token_hash === null) {
            $this->rejected($link, 'لم يُولَّد مفتاح الـ Webhook بعد', 401);

            return $this->reply(false, 'unauthorized', 401);
        }

        if (! $link->webhookAccepts($request->header($link->webhook_header))) {
            $this->rejected($link, $request->hasHeader($link->webhook_header)
                ? 'المفتاح في الترويسة «'.$link->webhook_header.'» لا يطابق'
                : 'لا ترويسة «'.$link->webhook_header.'» في الطلب', 401);

            return $this->reply(false, 'unauthorized', 401);
        }

        $payload = self::payload($request);

        if ($payload === []) {
            $this->rejected($link, 'طلبٌ بلا بيانات', 422);

            return $this->reply(false, 'empty body', 422);
        }

        $accepted = false;
        foreach (array_slice(array_is_list($payload) ? $payload : [$payload], 0, self::MAX_ITEMS) as $item) {
            if (is_array($item) && $inbox->accept($link, $item) !== null) {
                $accepted = true;
            }
        }

        $link->forceFill(['hook_last_at' => now()])->saveQuietly();

        // يُطبَّق بعد الردّ (docs/plan/106): تغيير الحالة وإشعارات الهواتف لا تؤخّر ردّنا عن مهلتهم،
        // وما لم يُطبَّق هنا يطبّقه المجدوِل خلال دقيقة
        if ($accepted && ($company = $tenant->company()) !== null) {
            defer(function () use ($company, $link, $inbox) {
                try {
                    Tenancy::runFor($company, fn () => $inbox->processPending($link, wait: 10, seconds: 15));
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        return $this->reply(true, 'ok');
    }

    /**
     * ما أرسلوه: JSON كما في وثائقهم، وإلّا حقول نموذج، أو JSON نصّاً في حقلٍ واحد (data). لا يُرفض تحديثٌ
     * لأنّ صيغته غير ما توقّعنا (docs/plan/107).
     */
    private static function payload(Request $request): array
    {
        $payload = $request->isJson() ? $request->json()->all() : [];

        if ($payload === [] && ($raw = trim((string) $request->getContent())) !== '' && in_array($raw[0], ['{', '['], true)) {
            $payload = json_decode($raw, true) ?: [];
        }

        if ($payload === []) {
            $payload = $request->request->all();
        }

        if (count($payload) === 1 && is_string($data = $payload['data'] ?? null)) {
            $payload = json_decode($data, true) ?: $payload;
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * طلبٌ منهم رُفض: يُكتب على الربط متى ولماذا (شاشة الربط تعرضه)، وفي سجلّ الخادم — فيُعرف لماذا لا تُقرأ
     * تحديثاتهم دون تخمين (docs/plan/107). لا يُكتب المفتاح ولا ما أرسلوه.
     */
    private function rejected(?AggregatorLink $link, string $reason, int $status): void
    {
        Log::warning('Aggregator webhook rejected', ['company_id' => $link?->company_id, 'status' => $status, 'reason' => $reason]);

        $link?->forceFill(['hook_rejected_at' => now(), 'hook_rejected_reason' => mb_substr($reason, 0, 120)])->saveQuietly();
    }

    /** بصيغة ردودهم نفسها */
    private function reply(bool $ok, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['status' => $ok, 'errNum' => $ok ? 'S000' : (string) $status, 'msg' => $message], $status);
    }
}
