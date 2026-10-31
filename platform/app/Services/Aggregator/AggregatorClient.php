<?php

namespace App\Services\Aggregator;

use App\Models\AggregatorLink;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * واجهة نظام الوسيط كما في وثائقهم (docs/plan/104). كل ردٍّ عندهم {status, errNum, msg, data}:
 * status = false خطأٌ برسالته، وما لم يكن JSON أو جاء بغير 2xx خطأٌ كذلك.
 *
 * - نقاط «الشركة» (aggre/…) برمز دخول الشركة: يعيش ١٥ يوماً، فيُجدَّد قبلها، وإن رُفض الطلب
 *   يُعاد الدخول مرّةً ويُعاد الطلب مرّةً.
 * - نقاط «التاجر» (merchant/…) برمز التاجر الذي نرسل باسمه، أو بلا رمز للقوائم العامة.
 */
class AggregatorClient
{
    private const TIMEOUT = 15;

    public function __construct(private readonly AggregatorLink $link) {}

    public static function for(AggregatorLink $link): self
    {
        return new self($link);
    }

    /** يدخل بحساب الشركة ويحفظ الرمز: يُستدعى من شاشة الربط ليتأكّد الحساب، وعند انتهاء الرمز */
    public function login(): string
    {
        $data = $this->send('post', 'aggre/login', [], [
            'username' => (string) $this->link->username,
            'password' => (string) $this->link->password,
        ]);

        $token = (string) ($data['token'] ?? '');
        if ($token === '') {
            throw new AggregatorException('لم يُرجع نظام الوسيط رمز دخول.');
        }

        $this->link->forceFill(['aggre_token' => $token, 'aggre_token_at' => now()])->save();

        return $token;
    }

    /** @return list<array{id: string, name: string, username: string, token: string}> */
    public function merchants(): array
    {
        return collect($this->aggre('get', 'aggre/get-merchants'))
            ->map(fn ($m) => [
                'id' => (string) ($m['id'] ?? ''),
                'name' => (string) ($m['name'] ?? ''),
                'username' => (string) ($m['username'] ?? ''),
                'token' => (string) ($m['token'] ?? ''),
            ])->filter(fn ($m) => $m['id'] !== '')->values()->all();
    }

    /**
     * ينشئ حتى ٣٠ طلباً بطلبٍ واحد. كل طلبٍ يحمل metadata = معرّف الشحنة عندنا ليُعرف في الردّ.
     *
     * @param  list<array<string, mixed>>  $orders
     * @return array{succeed: list<array<string, mixed>>, failed: list<array<string, mixed>>}
     */
    public function createOrders(array $orders): array
    {
        $data = $this->aggre('post', 'aggre/create-bulk-orders', [], [
            'data' => json_encode(array_values($orders), JSON_UNESCAPED_UNICODE),
        ]);

        return ['succeed' => array_values($data['succeed'] ?? []), 'failed' => array_values($data['failed'] ?? [])];
    }

    /** طلبٌ عندهم بهذا الرقم من نظامنا، أو null */
    public function findOrder(string $companyOrderId): ?array
    {
        $data = $this->aggre('get', 'aggre/check-order-exists-by-company-order-number', ['company-order-id' => $companyOrderId]);
        $orders = $data['order'] ?? [];
        if (is_string($orders)) {
            $orders = json_decode($orders, true) ?: [];
        }

        return is_array($orders) && isset($orders[0]) && is_array($orders[0]) ? $orders[0] : null;
    }

    /** استلمنا راجعه: يصير عندهم «راجع للتاجر» */
    public function receiveReturned(string $orderId): void
    {
        $this->aggre('post', 'aggre/receive-returned-order', [], [
            'merchant-token' => (string) $this->link->merchant_token,
            'order-id' => $orderId,
        ]);
    }

    /** @return array<string, string> رقم الحالة ← نصّها */
    public function statuses(): array
    {
        $rows = $this->send('get', 'merchant/statuses', ['token' => (string) $this->link->merchant_token]);

        return collect(is_array($rows) ? $rows : [])->mapWithKeys(function ($row) {
            $id = (string) ($row['id'] ?? '');
            // مثالهم يضع النصّ مفتاحاً والقيمة «status»: يُقرأ بالطريقين
            $text = $row['status'] ?? collect($row)->except('id')->keys()->first();

            return $id === '' ? [] : [$id => (string) $text];
        })->all();
    }

    /** @return array<string, string> */
    public function cities(): array
    {
        return $this->pairs($this->send('get', 'merchant/cities'), 'city_name');
    }

    /** @return array<string, string> */
    public function regions(string $cityId): array
    {
        return $this->pairs($this->send('get', 'merchant/regions', ['city_id' => $cityId]), 'region_name');
    }

    /** @return array<string, string> */
    public function packageSizes(): array
    {
        return $this->pairs($this->send('get', 'merchant/package-sizes'), 'size');
    }

    /** رمز الشركة الساري، يُجدَّد إن غاب أو قارب الانتهاء */
    private function token(): string
    {
        $fresh = $this->link->aggre_token_at?->gt(now()->subDays(AggregatorLink::TOKEN_DAYS));

        return filled($this->link->aggre_token) && $fresh ? (string) $this->link->aggre_token : $this->login();
    }

    /** نقطةٌ برمز الشركة: إن رُفضت يُعاد الدخول مرّةً واحدة */
    private function aggre(string $method, string $path, array $query = [], array $body = []): mixed
    {
        try {
            return $this->send($method, $path, ['token' => $this->token()] + $query, $body);
        } catch (AggregatorException $e) {
            // لم يردّ في الوقت، أو دخلنا للتوّ فليس الرمز: لا يُعاد
            if ($e instanceof AggregatorTimeout || $this->link->aggre_token_at?->gt(now()->subMinute())) {
                throw $e;
            }

            return $this->send($method, $path, ['token' => $this->login()] + $query, $body);
        }
    }

    private function send(string $method, string $path, array $query = [], array $body = []): mixed
    {
        $url = rtrim($this->link->base_url ?: AggregatorLink::DEFAULT_BASE_URL, '/').'/'.$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        try {
            $request = $this->http();
            $response = $method === 'get' ? $request->get($url) : $request->asMultipart()->post($url, $body);
        } catch (ConnectionException) {
            throw new AggregatorTimeout('لم يردّ نظام الوسيط في الوقت.');
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new AggregatorException('ردّ نظام الوسيط بغير المتوقّع (HTTP '.$response->status().').');
        }

        if (($json['status'] ?? false) !== true) {
            $message = trim((string) ($json['msg'] ?? ''));

            throw new AggregatorException(($message !== '' ? $message : 'رفض نظام الوسيط الطلب.')
                .(isset($json['errNum']) ? ' ('.$json['errNum'].')' : ''));
        }

        return $json['data'] ?? [];
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(self::TIMEOUT)->connectTimeout(8);
    }

    /** @return array<string, string> */
    private function pairs(mixed $rows, string $nameKey): array
    {
        return collect(is_array($rows) ? $rows : [])
            ->mapWithKeys(fn ($row) => isset($row['id']) ? [(string) $row['id'] => (string) ($row[$nameKey] ?? '')] : [])
            ->all();
    }
}
