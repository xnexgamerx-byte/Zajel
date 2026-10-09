<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\HtmlString;

/**
 * رقم الوصل في نصّ رسالةٍ رابطٌ إلى طلبه (docs/plan/47): في محادثة التاجر والمندوب والموظّفين، كلٌّ إلى
 * صفحة الطلب في شاشته — الموظّف في النظام، والتاجر في بوابته، والمندوب في تطبيقه — ولا يُربط إلّا طلبٌ
 * يراه القارئ. والنصّ يُهرَّب أوّلاً: الرابط وحده ما يُضاف.
 */
final class ShipmentLinks
{
    /**
     * رقمٌ من ٦ إلى ٨ خانات قائمٌ بنفسه، وقبله بادئة الشركة إن كانت لها (ZJ000123): لا جزءاً من هاتفٍ
     * (١١ خانة) ولا من مبلغٍ مفصول بفاصلة. وما وافق النمط ولا طلب له يبقى نصّاً.
     */
    private const NUMBER = '/(?<![\w,.])((?:[A-Z]{1,4}-?)?\d{6,8})(?![\d,.]*\d)/u';

    /** @var array<string, string|null> رابط كل رقمٍ سُئل عنه في هذا الطلب */
    private static array $seen = [];

    public static function text(?string $text, ?User $viewer = null): HtmlString
    {
        $escaped = e((string) $text);
        $viewer ??= auth()->user();

        if (! $viewer || ! preg_match_all(self::NUMBER, $escaped, $found)) {
            return new HtmlString($escaped);
        }

        $urls = self::urls(array_unique($found[1]), $viewer);

        return new HtmlString(preg_replace_callback(self::NUMBER, fn (array $m) => isset($urls[$m[1]])
            ? '<a href="'.e($urls[$m[1]]).'" class="num font-semibold underline underline-offset-2">'.$m[1].'</a>'
            : $m[1], $escaped));
    }

    /**
     * @param  list<string>  $numbers
     * @return array<string, string>
     */
    private static function urls(array $numbers, User $viewer): array
    {
        // لكل طلبٍ ذاكرته: ما رُبط في طلبٍ لا يُورث لطلبٍ بعده
        $request = spl_object_id(request());
        $key = fn (string $n) => $request.':'.$viewer->id.':'.$n;
        $ask = array_values(array_filter($numbers, fn ($n) => ! array_key_exists($key($n), self::$seen)));

        if ($ask !== []) {
            $query = Shipment::query()->whereIn('number', $ask);

            $query = match (true) {
                $viewer->role === UserRole::Merchant => $query->where('merchant_id', $viewer->merchant_id),
                $viewer->role === UserRole::Courier  => $query->where(fn ($q) => $q->where('delivery_courier_id', $viewer->courier_id)
                    ->orWhere('pickup_courier_id', $viewer->courier_id)),
                default                              => $query->visibleTo($viewer),
            };

            $route = match ($viewer->role) {
                UserRole::Merchant => 'portal.shipments.show',
                UserRole::Courier  => 'courier.shipments.show',
                default            => 'shipments.show',
            };

            $hits = $query->pluck('id', 'number');

            foreach ($ask as $number) {
                self::$seen[$key($number)] = isset($hits[$number]) ? route($route, $hits[$number]) : null;
            }
        }

        return array_filter(array_combine($numbers, array_map(fn ($n) => self::$seen[$key($n)], $numbers)));
    }
}
