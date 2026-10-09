<?php

namespace App\Services\Orders;

use App\Models\Governorate;
use App\Services\Orders\Ai\OrderModel;
use App\Services\Orders\Ai\UnreadableByModel;
use App\Support\Phone;
use Illuminate\Support\Facades\Log;

/**
 * قراءة الطلب بالذكاء الاصطناعي (docs/plan/40): لقطة شاشة لمحادثة الزبون، أو رسالته
 * ملصوقةً، أو كلام التاجر كما سمعه الهاتف — يقرؤها النموذج ويُرجعها حقولاً.
 *
 * النموذج يقول المحافظة من قائمة الشركة نفسها، والمنطقة كما وردت؛ ثم تُطابق المنطقة
 * بقوائم الشركة هنا (OrderReader::place)، فلا يُملأ إلّا ما في النظام. والهاتف يُوحَّد،
 * والمبلغ يُقبل في حدوده. وإن لم يكن مفتاح، أو تعذّرت القراءة، فـ null: يقرأ القارئ المحلّي.
 *
 * لا يُحفظ شيء: الجواب يملأ النموذج، والموظّف أو التاجر يراجع ويحفظ بنفسه.
 *
 * @phpstan-import-type Reading from OrderReader
 */
final class AiOrderReader
{
    /** أكبر صورةٍ تُرسَل كما هي؛ وما فوقها يُصغَّر أوّلاً (حدّ الواجهة ٥ ميغابايت بعد الترميز) */
    private const MAX_IMAGE_BYTES = 3_500_000;

    /** أطول ضلعٍ تُرسَل به الصورة: ما فوقه يصغّره النموذج على أيّ حال */
    private const MAX_SIDE = 1568;

    private const INSTRUCTIONS = <<<'TEXT'
        أنت تقرأ طلب توصيلٍ لشركة توصيلٍ عراقية، ليُملأ به نموذج الشحنة. يصلك واحدٌ من ثلاثة:
        - لقطة شاشةٍ لمحادثة الزبون (واتساب، ماسنجر، انستغرام): اسم المحادثة في رأسها غالباً اسم الزبون، وفيها أوقات الرسائل وأيقونات ليست من الطلب.
        - نصّ رسالة الزبون ملصوقاً، وقد يكون رسائل متتابعة.
        - كلام التاجر كما سمعه الهاتف: بلا فواصل، والأرقام فيه كلماتٌ باللهجة العراقية («ثنين»، «خمسطعش»، «ميتين»، «دبل سبعة» = 77).

        استخرج طلب الزبون — والأخير إن تعدّدت الطلبات — بهذه القواعد:
        - recipient_name: اسم المستلم كما كُتب. لا التحية، ولا اسم المتجر، ولا عبارةٌ مثل «طلب جديد».
        - recipient_phone: رقمٌ عراقيّ بصيغة 07 ثم تسعة أرقام (11 رقماً). حوّل +964 و00964، والأرقام العربية الهندية، والمنطوقة كلمات.
          الهاتف يُقال مقاطع تُلصق بترتيبها: رقماً رقماً، أو مئاتٍ وعشرات («اربعمية وعشرة» 410، «سبعمية وسبعين» 770، «سبعة وسبعين» 77)،
          و«ثلاث تساعات» 999، و«اربع اصفار» 0000، و«دبل سبعة» 77، و«تربل خمسة» 555. مثال: «صفر سبعة سبعة اربعمية وعشرة سبعة سبعة
          ثلاث تساعات» = 07741077999. احسب المقاطع حتى يصير الرقم 11 رقماً يبدأ بـ 07؛ وإن لم يصر فاكتب ما قيل كما هو.
        - recipient_phone_alt: رقمٌ ثانٍ للمستلم إن ذُكر.
        - governorate: إحدى محافظات القائمة المعطاة حرفياً، أو "" إن لم تُعرف. واستدلّ بالمدينة: الحلة بابل، والموصل نينوى، والناصرية ذي قار، والديوانية القادسية، والعمارة ميسان، والكوت واسط، والرمادي الأنبار، وبعقوبة ديالى، والسماوة المثنى، وتكريت صلاح الدين.
        - area: اسم المنطقة أو الحيّ كما ورد (الكرادة، حي الجهاد، العشار)، بلا المحافظة وبلا النقطة الدالّة.
        - landmark: أقرب نقطةٍ دالّة كما وردت (قرب الجامع، مقابل المستشفى…).
        - cod_amount: المبلغ الذي يُستلم من الزبون بالدينار عدداً صحيحاً. في العراق «25» و«25 الف» و«خمسة وعشرين» تعني 25000، و«ونص» بعد الألف 500. وإن ذُكر مجموعٌ شامل التوصيل فهو المبلغ. 0 إن لم يُذكر.
        - pieces_count: عدد القطع إن ذُكر، وإلّا 0.
        - notes: ملاحظةٌ للمندوب إن وردت (موعد، «اتصل قبل الوصول»، «قابل للكسر»)، بلا تكرار ما سبق.
        - text: نصّ الطلب كما قرأته، سطراً لكل معلومة، ليراجعه من أرسله.
        - is_order: false إن لم يكن في ما أُرسل طلب توصيلٍ أصلاً.

        إن لم تُذكر أسماء الحقول («الاسم»، «الرقم»، «المبلغ») فاعرف كلّ حقلٍ بنوعه: أسماء الأشخاص (كلمتان إلى أربع، ولو كانت نادرة) هي
        اسم المستلم، والرقم الطويل الذي يبدأ بصفر سبعة هو الهاتف، وأسماء المحافظات والمدن والأحياء وما بعد «قرب/مقابل/خلف» عنوان،
        والعدد مع «ألف» أو «دينار» أو العدد الأخير في الكلام بعد العنوان هو المبلغ. والمعتاد أن يُقال الاسم أوّلاً ثم الرقم ثم العنوان ثم المبلغ.
        وفي الكلام المسموع قد يُخطئ السامع حرفاً في اسمٍ أو منطقة: اكتب الاسم بإملائه الصحيح الشائع، والمنطقة كما تُعرف.

        لا تخترع شيئاً: ما لم يرد اتركه "" أو 0.
        TEXT;

    public function __construct(
        private readonly OrderReader $local,
        private readonly ?OrderModel $model,
    ) {}

    public function available(): bool
    {
        return $this->model !== null;
    }

    /** @return ?Reading */
    public function fromText(string $text, bool $spoken = false): ?array
    {
        $intro = $spoken ? 'كلام التاجر كما سمعه الهاتف:' : 'رسالة الزبون:';

        return $this->read([['type' => 'text', 'text' => $intro."\n\n".mb_substr($text, 0, 5000)]]);
    }

    /** @return ?Reading */
    public function fromImage(string $path, string $mime): ?array
    {
        $image = $this->image($path, $mime);

        return $image === null ? null : $this->read([
            $image,
            ['type' => 'text', 'text' => 'لقطة شاشةٍ لمحادثة الزبون: اقرأ الطلب منها.'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return ?Reading
     */
    private function read(array $content): ?array
    {
        if ($this->model === null) {
            return null;
        }

        $governorates = Governorate::offered()->get(['governorates.id', 'governorates.name_ar']);

        try {
            $answer = $this->model->extract([
                // التعليمات ثابتةٌ لكل الشركات: تُخزَّن عند Anthropic فتُقرأ أرخص وأسرع
                ['type' => 'text', 'text' => self::INSTRUCTIONS, 'cacheControl' => ['type' => 'ephemeral']],
                ['type' => 'text', 'text' => 'محافظات الشركة: '.$governorates->pluck('name_ar')->implode('، ').'.'],
            ], $content, $this->schema($governorates->pluck('name_ar')->all()));
        } catch (\Throwable $e) {
            // لا شيء من الطلب في السجلّ: نوع الخطأ وحده
            Log::warning('order reading by the model failed', ['error' => $e::class, 'reason' => $e instanceof UnreadableByModel ? $e->getMessage() : null]);

            return null;
        }

        return $this->reading($answer, $governorates);
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  \Illuminate\Support\Collection<int, Governorate>  $governorates
     * @return Reading
     */
    private function reading(array $answer, $governorates): array
    {
        $text = fn (string $key, int $max) => mb_substr(trim((string) ($answer[$key] ?? '')), 0, $max);
        $fields = [];
        $found = [];
        $warnings = [];

        if ($phone = Phone::normalise($text('recipient_phone', 40))) {
            $fields['recipient_phone'] = $found['recipient_phone'] = $phone;
        }
        if (($alt = Phone::normalise($text('recipient_phone_alt', 40))) && $alt !== $phone) {
            $fields['recipient_phone_alt'] = $alt;
        }

        $amount = (int) ($answer['cod_amount'] ?? 0);
        if ($amount >= 250 && $amount <= 50_000_000) {
            $fields['cod_amount'] = $amount;
            $found['cod_amount'] = number_format($amount).' د.ع';
        }

        $pieces = (int) ($answer['pieces_count'] ?? 0);
        if ($pieces >= 1 && $pieces <= 255) {
            $fields['pieces_count'] = $pieces;
        }

        // المحافظة كما قالها النموذج من القائمة، والمنطقة تُطابق بقوائم الشركة هنا
        $governorate = $governorates->firstWhere('name_ar', $text('governorate', 60));
        $area = $text('area', 120);
        $place = $area !== '' ? $this->local->place(trim(($governorate?->name_ar ?? '').' '.$area)) : null;
        $governorate ??= $place['governorate'] ?? null;

        if ($governorate) {
            $fields['governorate_id'] = $governorate->id;
            $found['governorate_id'] = $governorate->name_ar;
        }
        if (($city = $place['city'] ?? null) && (! $governorate || (int) $city->governorate_id === (int) $governorate->id)) {
            $fields['city_id'] = $city->id;
            $found['city_id'] = $city->name_ar;
        } elseif ($area !== '') {
            $warnings[] = $place['warning'] ?? "المنطقة «{$area}» ليست في قائمة المناطق: اخترها.";
        }

        if (($landmark = $text('landmark', 255)) !== '') {
            $fields['landmark'] = $found['landmark'] = $landmark;
        }
        if (($name = $text('recipient_name', 160)) !== '') {
            $fields['recipient_name'] = $found['recipient_name'] = $name;
        }
        if (($notes = $text('notes', 500)) !== '') {
            $fields['notes'] = $found['notes'] = $notes;
        }

        if (($answer['is_order'] ?? true) === false && $fields === []) {
            $warnings[] = 'لا طلب توصيلٍ في ما أُرسل.';
        }

        return [
            'fields'   => $fields,
            'found'    => $found,
            'missing'  => array_values(array_diff_key(OrderReader::REQUIRED, $fields)),
            'lines'    => array_values(array_filter(array_map('trim', preg_split('/\R/u', $text('text', 3000)) ?: []))),
            'warnings' => $warnings,
            'engine'   => 'ai',
        ];
    }

    /** @param list<string> $governorates @return array<string, mixed> */
    private function schema(array $governorates): array
    {
        $string = ['type' => 'string'];

        return [
            'type'       => 'object',
            'properties' => [
                'is_order'            => ['type' => 'boolean'],
                'recipient_name'      => $string,
                'recipient_phone'     => $string,
                'recipient_phone_alt' => $string,
                'governorate'         => ['type' => 'string', 'enum' => [...$governorates, '']],
                'area'                => $string,
                'landmark'            => $string,
                'cod_amount'          => ['type' => 'integer'],
                'pieces_count'        => ['type' => 'integer'],
                'notes'               => $string,
                'text'                => $string,
            ],
            'required' => ['is_order', 'recipient_name', 'recipient_phone', 'recipient_phone_alt', 'governorate', 'area',
                'landmark', 'cod_amount', 'pieces_count', 'notes', 'text'],
            'additionalProperties' => false,
        ];
    }

    /**
     * كتلة الصورة: تُصغَّر إلى ١٥٦٨ بكسل إن أمكن، وتُرسَل كما هي إن كانت صغيرة.
     * وما لا يُرسَل يُقرأ محلّياً (null).
     *
     * @return ?array<string, mixed>
     */
    private function image(string $path, string $mime): ?array
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $size = @getimagesizefromstring($bytes);
        // صورةٌ بأبعادٍ هائلة في ملفٍّ صغير لا تُفتح: تُترك للقارئ المحلّي فيردّها
        if ($size === false || $size[0] * $size[1] > 40_000_000) {
            return null;
        }

        if (function_exists('imagecreatefromstring') && max($size[0], $size[1]) > self::MAX_SIDE
            && ($source = @imagecreatefromstring($bytes))) {
            $scaled = imagescale($source, $size[0] >= $size[1] ? self::MAX_SIDE : (int) round($size[0] * self::MAX_SIDE / $size[1]));
            if ($scaled !== false) {
                ob_start();
                imagejpeg($scaled, null, 85);
                $bytes = (string) ob_get_clean();
                $mime = 'image/jpeg';
            }
        }

        if (strlen($bytes) > self::MAX_IMAGE_BYTES || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        return ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($bytes)]];
    }
}
