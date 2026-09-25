<?php

namespace App\Support;

use App\Models\Shipment;

/**
 * رابط التتبّع العامّ لشحنة: يفتحه الزبون بلا حساب.
 *
 * أرقام الوصولات متسلسلة، فرابطٌ برقم الوصل وحده يفتح شحنات الشركة كلّها
 * لمن يعدّ من ١ إلى المليون: أسماء الزبائن ومناطقهم ومبالغهم. فالرابط يحمل
 * بصمةً لا تُحزَر (HMAC بمفتاح التطبيق) تُطبع على الوصل رمزَ QR؛ ومن لا يملك
 * الوصل يتتبّع برقمه وآخر أربعة أرقام من هاتفه.
 */
final class Tracking
{
    /** ستّة عشر رمزاً (٦٤ بت): أقصر ما يبقى معه الحَزْر مستحيلاً، فيبقى الرمز على الملصق صغيراً. */
    public static function token(Shipment $shipment): string
    {
        return substr(hash_hmac(
            'sha256',
            'track|'.$shipment->company_id.'|'.$shipment->id.'|'.$shipment->number,
            (string) config('app.key'),
        ), 0, 16);
    }

    public static function verify(Shipment $shipment, string $token): bool
    {
        return hash_equals(self::token($shipment), $token);
    }

    public static function url(Shipment $shipment): string
    {
        return route('track.show', ['number' => $shipment->number, 'token' => self::token($shipment)]);
    }

    /** «علي ح.» — يكفي الزبون ليعرف أنها شحنته، ولا يكفي غيره ليعرفه. */
    public static function maskName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = $parts[0] ?? '';

        return isset($parts[1]) ? $first.' '.mb_substr($parts[1], 0, 1).'.' : $first;
    }
}
