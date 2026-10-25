<?php

namespace App\Support\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * الإرسال إلى هواتف التطبيقات عبر Firebase Cloud Messaging (docs/plan/82): واجهة HTTP v1، بتوقيع JWT
 * من مفتاح حساب الخدمة. المفتاح ملفٌّ على السيرفر خارج المستودع، ومساره في FIREBASE_SERVICE_ACCOUNT_PATH.
 *
 * بلا مفتاح يصمت الإرسال ولا يعطّل شيئاً: الإشعار يبقى في جرس التطبيق والبوّابة.
 */
final class Fcm
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** @return ?array{project_id: string, client_email: string, private_key: string} */
    public static function credentials(): ?array
    {
        $path = (string) config('services.firebase.service_account');

        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) && isset($json['project_id'], $json['client_email'], $json['private_key'])
            ? $json
            : null;
    }

    public static function enabled(): bool
    {
        return self::credentials() !== null;
    }

    /**
     * يرسل إشعاراً إلى رموز هواتفٍ بعينها.
     *
     * @param  list<string>  $tokens
     * @param  array<string, string>  $data
     * @return list<string> الرموز التي رفضتها Firebase نهائياً، لتُمسح من الجدول
     */
    public static function send(array $tokens, string $title, string $body, array $data = []): array
    {
        $creds = self::credentials();

        if ($creds === null || $tokens === []) {
            return [];
        }

        $access = self::accessToken($creds);

        if ($access === null) {
            return [];
        }

        $dead = [];
        $url = "https://fcm.googleapis.com/v1/projects/{$creds['project_id']}/messages:send";

        foreach ($tokens as $token) {
            $response = Http::withToken($access)->acceptJson()->timeout(8)->post($url, [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map('strval', $data),
                    'android' => ['priority' => 'HIGH'],
                ],
            ]);

            if ($response->status() === 404 || str_contains((string) $response->body(), 'UNREGISTERED')) {
                $dead[] = $token;
            } elseif (! $response->successful()) {
                Log::warning('FCM: الإرسال رُفض', ['status' => $response->status()]);
            }
        }

        return $dead;
    }

    /** رمز الوصول يُستعمل ساعةً، ويُحفظ نصف ساعة أقل من ذلك */
    private static function accessToken(array $creds): ?string
    {
        $cached = Cache::get('fcm.access_token');

        if (is_string($cached)) {
            return $cached;
        }

        $now = time();
        $input = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.self::b64(json_encode([
                'iss' => $creds['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

        if (! openssl_sign($input, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
            Log::warning('FCM: تعذّر توقيع الطلب بمفتاح الخدمة');

            return null;
        }

        $response = Http::asForm()->timeout(8)->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $input.'.'.self::b64($signature),
        ]);

        $token = $response->successful() ? $response->json('access_token') : null;

        if (is_string($token)) {
            Cache::put('fcm.access_token', $token, now()->addMinutes(50));

            return $token;
        }

        Log::warning('FCM: لم تُعطَ رمز وصول', ['status' => $response->status()]);

        return null;
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
