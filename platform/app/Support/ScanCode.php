<?php

namespace App\Support;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;

/**
 * ما كتبه الماسح في الحقل، مقروءاً رمزاً يُبحث به.
 *
 * على الوصل رمزان: الباركود (رقم الوصل، أو رقم الوصل المطبوع مسبقاً) ورمز QR يحمل
 * رابط التتبّع (Tracking::url). والماسح يكتب ما قرأه كما هو: الرقم، أو الرابط كاملاً.
 * فمن الرابط يُقرأ رقم الوصل، وتُطابَق بصمته: كل شركةٍ على المنصّة تعدّ وصولاتها من
 * واحد، فرمزٌ من شركةٍ أخرى قد يحمل رقم شحنةٍ عندنا — وبصمته لا تطابقها.
 *
 * والماسح لوحةُ مفاتيح: إن كانت لغة الجهاز العربية كتب حروف الرابط بمواضعها في
 * اللوحة العربية («اففحس:ظظ…» بدل «https://…»). الأرقام لا تتغيّر — فالباركود يُقرأ
 * على اللغتين —، والرابط يُعاد إلى حروفه.
 */
final class ScanCode
{
    /** أطول ما يُقبل من الحقل: رابطٌ كامل بنطاقه */
    public const MAX_INPUT = 300;

    /** أطول رمزٍ يُبحث به: رقم وصل أو باركود */
    public const MAX_CODE = 40;

    /** /t/{رقم الوصل}/{البصمة} كما يبنيه Tracking::url */
    private const LINK = '~/t/([A-Za-z0-9\-]+)/([a-f0-9]{16})(?![a-f0-9])~i';

    /**
     * لوحة المفاتيح العربية (101) على مواضع الإنكليزية: ما يكتبه الماسح والجهاز
     * على العربية. «لا» وأخواتها قبل «ل» و«ا»: strtr يبدأ بالأطول.
     */
    private const ARABIC_KEYS = [
        'لا' => 'b', 'لأ' => 'G', 'لإ' => 'T', 'لآ' => 'B',
        'ض' => 'q', 'ص' => 'w', 'ث' => 'e', 'ق' => 'r', 'ف' => 't', 'غ' => 'y', 'ع' => 'u', 'ه' => 'i',
        'خ' => 'o', 'ح' => 'p', 'ج' => '[', 'د' => ']', 'ش' => 'a', 'س' => 's', 'ي' => 'd', 'ب' => 'f',
        'ل' => 'g', 'ا' => 'h', 'ت' => 'j', 'ن' => 'k', 'م' => 'l', 'ك' => ';', 'ط' => "'", 'ئ' => 'z',
        'ء' => 'x', 'ؤ' => 'c', 'ر' => 'v', 'ى' => 'n', 'ة' => 'm', 'و' => ',', 'ز' => '.', 'ظ' => '/',
        'ذ' => '`', 'أ' => 'H', 'إ' => 'Y', 'آ' => 'N', '؛' => 'P', '،' => 'K', '؟' => '?', 'ـ' => 'J',
    ];

    private function __construct(
        public readonly string $code,
        public readonly ?string $token,
    ) {}

    public static function read(?string $raw): self
    {
        $text = Phone::latinDigits(trim((string) $raw));
        // علامات الاتّجاه والفواصل الخفيّة: يُلحقها بعض الأجهزة بما تكتب
        $text = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text);

        foreach ([$text, strtr($text, self::ARABIC_KEYS)] as $candidate) {
            if (preg_match(self::LINK, $candidate, $m)) {
                return new self($m[1], strtolower($m[2]));
            }
        }

        return new self((string) preg_replace('/\s+/u', '', $text), null);
    }

    /** قُرئ من رمز QR (رابط التتبّع) لا من الباركود */
    public function isLink(): bool
    {
        return $this->token !== null;
    }

    /** يصلح للبحث: لا فارغ ولا أطول من رقم وصل */
    public function usable(): bool
    {
        return $this->code !== '' && mb_strlen($this->code) <= self::MAX_CODE;
    }

    /** يحصر الاستعلام في شحنات هذا الرمز: برقمها أو باركودها، والرابط برقمها. */
    public function constrain(Builder $query): Builder
    {
        return $this->isLink()
            ? $query->where('number', $this->code)
            : $query->where(fn (Builder $q) => $q->where('number', $this->code)->orWhere('barcode', $this->code));
    }

    /** هل هذه الشحنة صاحبة الرمز — وللرابط: بصمته بصمتها */
    public function matches(Shipment $shipment): bool
    {
        if ($this->isLink()) {
            return $shipment->number === $this->code && Tracking::verify($shipment, (string) $this->token);
        }

        return $shipment->number === $this->code || $shipment->barcode === $this->code;
    }

    /** الشحنة من الاستعلام المعطى، بما فيه من حصرٍ للرؤية؛ أو null. */
    public function find(Builder $query): ?Shipment
    {
        if (! $this->usable()) {
            return null;
        }

        return $this->constrain($query)->get()->first(fn (Shipment $shipment) => $this->matches($shipment));
    }

    /** لماذا لم يُعثر عليه، بكلامٍ يُقال للموظّف عند العدّاد */
    public function notFound(): string
    {
        return $this->isLink()
            ? 'رمز QR هذا ليس لوصلٍ من وصولاتنا.'
            : "لا وصل برقم {$this->code}.";
    }
}
