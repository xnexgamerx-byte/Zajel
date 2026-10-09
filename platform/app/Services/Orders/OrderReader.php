<?php

namespace App\Services\Orders;

use App\Models\City;
use App\Models\Governorate;
use App\Services\Import\ShipmentSheet;
use App\Support\Arabic;
use App\Support\Phone;

/**
 * طلبٌ من رسالة زبون: نصٌّ ملصوق، أو لقطة شاشة تُقرأ على الخادم (ScreenshotText).
 *
 * يُستخرج منه ما يملأ نموذج الشحنة: الهاتف، والمحافظة والمنطقة من قوائم الشركة نفسها،
 * والنقطة الدالّة، والمبلغ، والعدد، والاسم والملاحظة. لا يُحفظ شيء: الموظّف أو التاجر
 * يراجع ما مُلئ ويحفظ بنفسه، وما لم يُعثر عليه يُقال.
 *
 * المبلغ كما يُكتب في العراق: «25 الف» و«25,000» و«السعر 25» كلّها خمسةٌ وعشرون ألفاً.
 *
 * @phpstan-type Reading array{fields: array<string, string|int>, found: array<string, string>,
 *     missing: list<string>, lines: list<string>, warnings: list<string>}
 */
final class OrderReader
{
    /** ما لا تُكتب الشحنة بدونه، بأسمائه في النموذج */
    public const REQUIRED = ['recipient_phone' => 'رقم الهاتف', 'governorate_id' => 'المحافظة',
        'city_id' => 'المنطقة', 'cod_amount' => 'المبلغ'];

    /** رقمٌ عراقيّ: 07 وتسعة، أو 964+ بلا صفر، بمسافاتٍ وشرطاتٍ أو بدونها */
    private const PHONE = '/(?<!\d)(?:(?:\+|00)?964|0)?[\s\-]*7\d(?:[\s\-]?\d){8}(?!\d)/u';

    private const NAME_LABEL = '/^\s*(?:(?:ال)?[اأإآ]سم(?:\s+(?:الزبون|المستلم|العميل))?|الزبون|المستلم|العميل|اسمي)\s*[:：\-–]?\s*(.+)$/u';

    private const NOTE_LABEL = '/^\s*(?:ال)?ملاحظ(?:ة|ه|ات)\s*[:：\-–]?\s*(.+)$/u';

    /** كلماتٌ يُعرف بها سطر المبلغ — مطويّةً (Arabic::fold) */
    private const PRICE_WORDS = 'السعر|سعر|سعرها|سعره|بسعر|المبلغ|مبلغ|الحساب|حسابها|الكلفه|كلفه|التكلفه|المطلوب';

    /** المبلغ الكامل: يُقدَّم على ما سواه إن ذُكر أكثر من مبلغ */
    private const TOTAL_WORDS = 'المجموع|مجموع|الاجمالي|اجمالي|الكلي|مع التوصيل|شامل التوصيل';

    /** كلماتٌ تبدأ بها النقطة الدالّة — مطويّةً */
    private const LANDMARK_WORDS = 'قرب|مقابل|خلف|جنب|يم|بجانب|شارع|زقاق|محله|منطقه|حي|قريه|ناحيه|دار|عماره|مجمع|تقاطع|ساحه|جامع|مسجد|مدرسه|مستشفي|سوق|بنايه';

    /** تحيّةٌ وردٌّ وعنوان محادثة: ليست اسماً — مطويّةً */
    private const SMALL_TALK = '/^(?:السلام عليكم|سلام عليكم|مرحبا|هلا|اهلا|هلو|هاي|شكرا|تمام|اوكي|اوك|ok|okay|نعم|اي|زين|حاضر|وصل طلبك|طلب جديد|زبون جديد|صباح الخير|صباح النور|مساء الخير|مساء النور|الله يسلمك|حياك الله|تسلم|مشكور)(?:\s|$)/u';

    /**
     * رسائلُ منسوخةٌ معاً من واتساب تبدأ بوقتها ومُرسلها: «[10:12 م، 5/10/2026] علي: …»،
     * أو في المحادثة المصدَّرة «5/10/26، 10:12 م - علي: …». والمُرسل اسمه المحفوظ أو رقمه.
     */
    private const CHAT_PREFIX = '/^\s*(?:\[[^\]]{4,40}\]|\d{1,4}[\/.\-]\d{1,2}[\/.\-]\d{1,4}[,،]?\s+\d{1,2}[:.]\d{2}(?:\s*(?:[AaPp]\.?[Mm]\.?|[مص]))?\s+[\-–])\s*(?<sender>[^:]{1,40}?)\s*:\s/u';

    /** تحيّاتٌ فيها أسماء مناطق («حي السلام»، «حي النور») — مطويّةً، تُحذف قبل البحث عن المنطقة */
    private const GREETINGS = '/(?<=^| )(?:السلام عليكم|سلام عليكم|وعليكم السلام|عليكم السلام|صباح الخير|صباح النور|مساء الخير|مساء النور|مرحبا|اهلا|اهلين|هلا|الله يسلمك|الله يحفظك|حياك الله|يعطيك العافيه|الحمد لله|شكرا|مشكور|تسلم)(?= |$)/u';

    /** علامة الوقت بعد الساعة: م/ص، أو AM/PM */
    private const MERIDIEM = '(?:[مص]|[AaPp]\.?[Mm]\.?)';

    /** كلماتٌ في أسماء المناطق لا تميّز منطقةً من أخرى — مطويّةً للمطابقة (matchFold) */
    private const GENERIC_WORDS = ['حي', 'منطقا', 'محلا', 'شارع', 'زقاق', 'قريا', 'ناحيا', 'قضاء', 'مركز', 'مجمع', 'قرب', 'طريق',
        'مدينا', 'قطاع', 'دور', 'تقاطع', 'ساحا', 'جسر', 'مقابل', 'خلف'];

    /** الجهات: تميّز منطقةً من أختها («الحمزة الغربي»)، ولا تكفي وحدها */
    private const DIRECTIONS = ['غربي', 'شرقي', 'شمالي', 'جنوبي', 'غرب', 'شرق', 'شمال', 'جنوب', 'غربيا', 'شرقيا', 'شماليا', 'جنوبيا'];

    /** وصفٌ كالجهة لا يكفي وحده: «جديد» في «طلب جديد» ليست «جسر ديالى الجديد» */
    private const WEAK_WORDS = [...self::DIRECTIONS, 'جديد', 'جديدا', 'قديم', 'قديما', 'كبير', 'كبيرا', 'صغير', 'صغيرا', 'اول', 'اولي',
        'ثاني', 'ثانيا', 'ثالث', 'ثالثا', 'عام', 'عاما', 'اعلي', 'وسط', 'وسطي'];

    /** كلمات النقطة الدالّة مطويّةً للمطابقة: ما بعدها ليس منطقة */
    private const LANDMARK_TOKENS = ['قرب', 'مقابل', 'خلف', 'جنب', 'يم', 'بجانب', 'شارع', 'زقاق', 'جامع', 'مسجد', 'مدرسا', 'مستشفي', 'سوق', 'بنايا'];

    /** كلماتٌ يبدأ بها كلام المحادثة لا الطلب — مطويّةً */
    private const CHAT_WORDS = ['اريد', 'ابي', 'ابغي', 'اريدها', 'اريده', 'شوكت', 'شلون', 'وين', 'شنو', 'ليش', 'متي', 'هل', 'دزلي', 'دز',
        'ارسل', 'ارسلي', 'اكيد', 'مرات', 'يعني', 'بس', 'لا', 'نعم', 'اي', 'ايه', 'عندي', 'عدكم', 'عدك', 'اكو', 'ماكو', 'حبيبي',
        'حبيبتي', 'اخي', 'اختي', 'عيوني', 'ممكن', 'رجاء', 'رجاءا', 'لو', 'اذا', 'كم', 'متوفر', 'موجود', 'انطلع', 'اطلع', 'خلي',
        'خليها', 'خليه', 'هسه', 'باچر', 'باجر', 'اليوم', 'وصل', 'وصلت', 'ان', 'انشاء', 'الله', 'والله', 'هاي', 'هذا', 'هذه', 'هذي'];

    /** عناوين حقولٍ تُكتب قبل العنوان — مطويّةً، تُحذف من النقطة الدالّة */
    private const ADDRESS_LABELS = ['العنوان', 'عنوان', 'المحافظه', 'محافظه', 'المنطقه', 'منطقه', 'اقرب', 'نقطه', 'داله'];

    public function __construct(private readonly ScreenshotText $screenshots) {}

    /** @return Reading */
    public function fromImage(string $path): array
    {
        return $this->fromText($this->screenshots->read($path), titled: true);
    }

    /**
     * المحافظة والمنطقة من قوائم الشركة لعنوانٍ مكتوبٍ نظيف — لما يقرؤه الذكاء الاصطناعي
     * (AiOrderReader): يقول المنطقة كما وردت، وتُطابق هنا بالقواعد نفسها.
     *
     * @return array{governorate: ?Governorate, city: ?object, landmark: string, warning: ?string}
     */
    public function place(string $address): array
    {
        $found = $this->address([trim($address)]);

        return array_intersect_key($found, array_flip(['governorate', 'city', 'landmark', 'warning']));
    }

    /**
     * الطلب بصوت التاجر (docs/plan/40): ما سمعه المتصفّح نصّاً متّصلاً، يُعاد أسطراً
     * وأرقاماً (SpokenOrder) ثم يُقرأ كأيّ رسالة.
     *
     * @return Reading
     */
    public function fromSpeech(string $speech): array
    {
        // اسمٌ قيل بلا «الاسم» متّصلاً بعنوانه («علي حسين بغداد الكرادة…»): يُفصلان سطرين
        $lines = [];
        foreach (explode("\n", SpokenOrder::normalise($speech)) as $line) {
            array_push($lines, ...$this->splitSpokenName($line));
        }

        // وفي الكلام أوّل ما قبل الرقم من غير المكان اسمُ الزبون، ولو لم يكن في قائمة الأسماء الأولى:
        // يُعنوَن «الاسم:» فيُقرأ اسماً لا ملاحظة
        $named = $this->spokenNameLine($lines);
        if ($named !== null) {
            $lines[$named] = 'الاسم: '.$lines[$named];
        }

        $reading = $this->fromText(implode("\n", $lines));

        // اسمٌ من كلمةٍ واحدة («زينب») لا يُقرأ بعنوانه: يُكتب هنا
        if ($named !== null && ! isset($reading['fields']['recipient_name'])) {
            $name = $this->trimValue(mb_substr($lines[$named], mb_strlen('الاسم: ')));
            $reading['fields']['recipient_name'] = $reading['found']['recipient_name'] = $name;
            if (($reading['fields']['notes'] ?? null) === $name) {
                unset($reading['fields']['notes'], $reading['found']['notes']);
            }
        }

        return $reading;
    }

    /**
     * سطرٌ أوّله اسمٌ وباقيه عنوان: أطول ما في أوّله من كلماتٍ لا مكان فيها وبعدها محافظةٌ أو منطقة.
     *
     * @return list<string>
     */
    private function splitSpokenName(string $line): array
    {
        if (str_contains($line, ':') || preg_match('/\d/', $line) || ! $this->isPlace($line)) {
            return [$line];
        }

        $words = explode(' ', $line);
        $split = null;
        for ($k = 1; $k <= min(4, count($words) - 1); $k++) {
            $name = implode(' ', array_slice($words, 0, $k));
            $rest = implode(' ', array_slice($words, $k));
            if ($this->couldBeName($name) && ! $this->isPlace($name) && $this->isPlace($rest)) {
                $split = [$name, $rest];
            }
        }

        return $split ?? [$line];
    }

    /** @param list<string> $lines */
    private function spokenNameLine(array $lines): ?int
    {
        if (preg_grep(self::NAME_LABEL, $lines)) {
            return null;
        }

        $phone = array_key_first(array_filter($lines, fn (string $line) => preg_match(self::PHONE, $line) === 1));
        foreach ($lines as $i => $line) {
            if ($phone !== null && $i >= $phone) {
                break;
            }
            if (! str_contains($line, ':') && $this->couldBeName($line) && ! $this->isPlace($line)) {
                return $i;
            }
        }

        // «الرقم … واسمه علي» بلا كلمته: السطر بعد الرقم إن لم يكن قبله شيء
        $after = $phone !== null ? ($lines[$phone + 1] ?? null) : null;

        return $after !== null && $this->couldBeName($after) && ! $this->isPlace($after) && preg_match('/\d/', $after) === 0
            ? $phone + 1 : null;
    }

    /** كلماتٌ عربيةٌ من واحدةٍ إلى أربع، لا تحيّة ولا مبلغ ولا نقطةٌ دالّة ولا كلام محادثة */
    private function couldBeName(string $text): bool
    {
        $text = $this->trimValue($text);
        $folded = $this->fold($text);
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($words) >= 1 && count($words) <= 4
            && preg_match('/^[\p{Arabic}\s]+$/u', $text) === 1
            && min(array_map('mb_strlen', $words)) >= 2
            && preg_match(self::SMALL_TALK, $folded) === 0
            // «الله» في «عبد الله» اسم، لا كلام محادثة
            && array_intersect(explode(' ', $folded), array_diff(self::CHAT_WORDS, ['الله'])) === []
            && preg_match('/(?:^| )(?:'.self::LANDMARK_WORDS.'|'.self::PRICE_WORDS.'|العدد|عدد|قطع|ملاحظه|اريد|اطلب|رقم|الرقم)(?: |$)/u', $folded) === 0;
    }

    private function isPlace(string $text): bool
    {
        $place = $this->place($text);

        return $place['governorate'] !== null || $place['city'] !== null;
    }

    /**
     * @param bool $titled لقطة شاشة: في رأسها اسم المحادثة، وهو غالباً اسم الزبون
     * @return Reading
     */
    public function fromText(string $text, bool $titled = false): array
    {
        [$lines, $senders, $blocks] = $this->clean($text);
        $fields = [];

        // رأس المحادثة في أوّل الصورة: اسمٌ أوّل وكلمةٌ أو أكثر («الزهراء قدوتي»)، بعد ما قد يسبقه
        // من أيقونات. ليس عنواناً وإن شابه اسم منطقة («حي الزهراء»)
        $title = null;
        if ($titled) {
            foreach (array_slice($lines, 0, 4, true) as $i => $line) {
                if ($this->isName($line)) {
                    $title = $i;
                    break;
                }
            }
        }
        $found = [];
        $warnings = [];

        // الهاتف: أوّل رقمٍ عراقيّ، والثاني في سطره أو بجانبه هاتفٌ بديل
        $phones = $this->phones($lines);
        if ($phones !== []) {
            $fields['recipient_phone'] = $found['recipient_phone'] = $phones[0]['phone'];

            $second = collect($phones)->first(fn (array $p) => $p['phone'] !== $phones[0]['phone']);
            if ($second && abs($second['line'] - $phones[0]['line']) <= 1) {
                $fields['recipient_phone_alt'] = $second['phone'];
            } elseif ($second) {
                $warnings[] = 'في الرسالة أكثر من رقم: أُخذ الأوّل '.$phones[0]['phone'].'.';
            }
        } elseif ($phone = collect($senders)->map(fn (string $sender) => preg_match(self::PHONE, $sender) ? Phone::normalise($sender) : null)->filter()->first()) {
            // لا رقم في نصّ الرسائل: رقم مُرسلها في واتساب، وهو غالباً رقم الزبون
            $fields['recipient_phone'] = $found['recipient_phone'] = $phone;
            $warnings[] = 'الرقم '.$phone.' رقم مُرسل الرسالة في واتساب: تأكّد أنّه رقم الاستلام.';
        }

        // في الصورة: رقمٌ وحده في رأسها (أيقونةٌ قُرئت رقماً) ليس مبلغاً
        if ($price = $this->price($lines, $titled ? max(2, ($title ?? -1) + 1) : 0)) {
            $fields['cod_amount'] = $price;
            $found['cod_amount'] = number_format($price).' د.ع';
        } elseif ($titled && preg_grep('/(?:^| )(?:'.self::PRICE_WORDS.'|بسعر|الف)(?: |$)/u', array_map(fn (string $line) => $this->plain($line), $lines))) {
            // «بسعر ٣٠ الف» في سطر إعلانٍ صغير لم تُقرأ أرقامه
            $warnings[] = 'في الصورة سعرٌ لم تُقرأ أرقامه: اكتبه.';
        }

        if ($pieces = $this->pieces($lines)) {
            $fields['pieces_count'] = $pieces;
        }

        // العنوان: المحافظة والمنطقة من قوائم الشركة، وما بقي من سطرهما نقطةٌ دالّة
        $address = $this->address($lines, $title);
        if ($address['governorate']) {
            $fields['governorate_id'] = $address['governorate']->id;
            $found['governorate_id'] = $address['governorate']->name_ar;
        }
        if ($address['city']) {
            $fields['city_id'] = $address['city']->id;
            $found['city_id'] = $address['city']->name_ar;
        }
        if ($address['landmark'] !== '') {
            $fields['landmark'] = $found['landmark'] = $address['landmark'];
        }
        if ($address['warning'] !== null) {
            $warnings[] = $address['warning'];
        }

        // الاسم والملاحظة بعنوانيهما، ثم اسم المحادثة في رأس الصورة، ثم سطرٌ باسمٍ أوّل بجانب
        // الهاتف، ثم اسم مُرسل الرقم في واتساب كما حفظه التاجر
        $used = [];
        foreach ($lines as $i => $line) {
            if (! isset($fields['notes']) && preg_match(self::NOTE_LABEL, $line, $m)) {
                $fields['notes'] = $this->trimValue($m[1]);
                $used[] = $i;
            } elseif (! isset($fields['recipient_name']) && preg_match(self::NAME_LABEL, $line, $m) && $this->looksLikeName($m[1])) {
                $fields['recipient_name'] = $this->trimValue($m[1]);
                $used[] = $i;
            }
        }
        if (! isset($fields['recipient_name']) && $title !== null) {
            $fields['recipient_name'] = $this->trimValue($lines[$title]);
            $used[] = $title;
        }
        if (! isset($fields['recipient_name']) && $phones !== []) {
            foreach ([$phones[0]['line'] - 1, $phones[0]['line'] + 1] as $i) {
                if (isset($lines[$i]) && ! in_array($i, $address['lines'], true) && $this->isName($lines[$i])) {
                    $fields['recipient_name'] = $this->trimValue($lines[$i]);
                    $used[] = $i;
                    break;
                }
            }

            $sender = $senders[$phones[0]['line']] ?? null;
            if (! isset($fields['recipient_name']) && $sender !== null && $this->isName($sender)) {
                $fields['recipient_name'] = $this->trimValue($sender);
            }
        }
        if (isset($fields['recipient_name'])) {
            $found['recipient_name'] = $fields['recipient_name'];
        }

        // وما بقي في رسالة الطلب نفسها («منقلة»، «توصيل مستعجل») ملاحظة
        $order = $phones !== [] ? $phones[0]['line'] : ($address['lines'][0] ?? null);
        if ($order !== null) {
            $used = [...$used, ...array_column($phones, 'line'), ...$address['lines']];
            $rest = [];
            foreach ($lines as $i => $line) {
                if (($blocks[$i] ?? 0) === ($blocks[$order] ?? 0) && ! in_array($i, $used, true) && $this->isNote($line)) {
                    $rest[] = $this->trimValue($line);
                }
            }
            if ($rest !== []) {
                $fields['notes'] = mb_substr(implode('، ', array_filter([$fields['notes'] ?? null, ...$rest])), 0, 500);
                $found['notes'] = $fields['notes'];
            }
        }

        return [
            'fields'   => $fields,
            'found'    => $found,
            'missing'  => array_values(array_diff_key(self::REQUIRED, $fields)),
            'lines'    => array_values($lines),
            'warnings' => $warnings,
        ];
    }

    /**
     * أسطرٌ بلا أوقات الرسائل، ولا ما لا حرف فيه ولا رقم — ومُرسل كلّ سطرٍ إن نُسخت الرسائل
     * معاً من واتساب (والسطر بلا بادئةٍ تكملةُ رسالة مُرسله).
     *
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, int>} الأسطر، ومُرسلوها،
     *     ورقم رسالة كلّ سطر
     */
    private function clean(string $text): array
    {
        $text = Phone::latinDigits((string) preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text));
        $text = strtr($text, ['٫' => '.', '٬' => ',']);
        $lines = [];
        $senders = [];
        $blocks = [];
        $sender = null;
        $block = 0;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            // رسالةٌ عن رسالة: سطرٌ فارغ (وبين فقاعات الصورة سطرٌ فارغ)، أو بادئة رسالةٍ منسوخة
            if (trim($line) === '' && $lines !== [] && ($blocks[array_key_last($lines)] ?? 0) === $block) {
                $block++;
            }
            if (preg_match(self::CHAT_PREFIX, $line, $m)) {
                $sender = $this->trimValue($m['sender']);
                $line = mb_substr($line, mb_strlen($m[0]));
                $block += $lines !== [] && ($blocks[array_key_last($lines)] ?? 0) === $block ? 1 : 0;
            }

            // وقت الرسالة: «10:42 م» في آخر الفقاعة، أو ما بقي منه بعد القراءة («2 م»). وبنقطةٍ
            // («10.42 م») وقتٌ بعلامة م أو ص وحدها: «السعر 22.50 الف» مبلغ
            $line = (string) preg_replace('/(^|\s)(?:\d{1,2}:\d{2}(?:\s*'.self::MERIDIEM.')?|\d{1,2}\.\d{2}\s*'.self::MERIDIEM.')(?=\s|$)/u', ' ', $line);
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));

            if ($line === '' || preg_match('/^\d{0,4}\s*[مص]{1,2}$/u', $line) || ! preg_match('/[\p{L}\d]{2}/u', $line)) {
                continue;
            }

            $lines[] = $line;
            $blocks[array_key_last($lines)] = $block;
            if ($sender !== null) {
                $senders[array_key_last($lines)] = $sender;
            }
        }

        return [$lines, $senders, $blocks];
    }

    /** @param array<int, string> $lines @return list<array{phone: string, line: int}> */
    private function phones(array $lines): array
    {
        $phones = [];

        foreach ($lines as $i => $line) {
            preg_match_all(self::PHONE, $line, $matches);

            foreach ($matches[0] as $raw) {
                if ($phone = Phone::normalise($raw)) {
                    $phones[] = ['phone' => $phone, 'line' => $i];
                }
            }
        }

        return $phones;
    }

    /**
     * المبلغ: رقمٌ بعده «الف»، أو بعد كلمة السعر، أو قبل «دينار». و«السعر 25» بلا ألف خمسةٌ
     * وعشرون ألفاً كما يُكتب. والمجموع يُقدَّم، ثم آخر ما ذُكر: الرسالة الأخيرة تصحّح ما قبلها.
     *
     * @param array<int, string> $lines
     * @param int $loneFrom أوّل سطرٍ يُقبل فيه رقمٌ وحده مبلغاً
     */
    private function price(array $lines, int $loneFrom = 0): ?int
    {
        // الرقم كاملاً لا بعضه: «25 الف» لا تُقرأ «2» بعد كلمة السعر
        $number = '(\d{1,3}(?:[,.،\s]\d{3})+|\d+(?:[.,]\d{1,2})?)(?![\d.,،])';
        $best = null;
        $lone = null;

        foreach ($lines as $i => $line) {
            $folded = $this->plain((string) preg_replace(self::PHONE, ' ', $line));
            $total = preg_match('/(?:'.self::TOTAL_WORDS.')/u', $folded) === 1;
            $candidates = [];

            if (preg_match_all('/'.$number.'\s*(?:الف|الاف|k)(?!\p{L})/iu', $folded, $m)) {
                foreach ($m[1] as $raw) {
                    $candidates[] = $this->amount($raw, thousands: true);
                }
            }
            if (preg_match_all('/(?:'.self::PRICE_WORDS.'|'.self::TOTAL_WORDS.')\s*[:=\-]?\s*'.$number.'(?!\s*(?:الف|الاف|k)(?!\p{L}))/iu', $folded, $m)) {
                foreach ($m[1] as $raw) {
                    $candidates[] = $this->amount($raw, thousands: false);
                }
            }
            if (preg_match_all('/'.$number.'\s*(?:دينار|د\.?\s?ع(?!\p{L})|iqd)/iu', $folded, $m)) {
                foreach ($m[1] as $raw) {
                    $candidates[] = $this->amount($raw, thousands: false);
                }
            }

            foreach (array_filter($candidates) as $amount) {
                if ($best === null || $total || ! $best['total']) {
                    $best = ['amount' => $amount, 'total' => $total];
                }
            }

            // سطرٌ رقمٌ وحده («25»، «27500»): المبلغ إن لم يُذكر بكلمته — لا سنةٌ ولا رقم بيت
            if ($candidates === [] && $i >= $loneFrom && preg_match('/^(?!0)'.$number.'$/u', trim($folded), $m)
                && ($amount = $this->amount($m[1], thousands: false)) && $amount % 250 === 0) {
                $lone = $amount;
            }
        }

        return $best['amount'] ?? $lone;
    }

    /** «25» و«25 الف» و«25,000» و«2.5 الف» — وما دون الألف بلا «الف» يُعدّ بالآلاف كما يُكتب */
    private function amount(string $raw, bool $thousands): ?int
    {
        $raw = trim($raw);
        $value = preg_match('/^\d{1,3}(?:[,.،\s]\d{3})+$/u', $raw)
            ? (float) preg_replace('/\D/u', '', $raw)
            : (float) str_replace(',', '.', $raw);

        if ($thousands || $value < 1000) {
            $value *= 1000;
        }

        $value = (int) round($value);

        return $value >= 250 && $value <= 50_000_000 ? $value : null;
    }

    /** @param array<int, string> $lines */
    private function pieces(array $lines): ?int
    {
        foreach ($lines as $line) {
            $folded = $this->fold($line);
            if (preg_match('/(?:العدد|عدد)\s*[:=\-]?\s*(\d{1,3})(?!\d)/u', $folded, $m)
                || preg_match('/(?<!\d)(\d{1,3})\s*(?:قطع|قطعه|حبات|حبه)(?!\p{L})/u', $folded, $m)) {
                $count = (int) $m[1];

                return $count >= 1 && $count <= 255 ? $count : null;
            }
            if (preg_match('/(?:^| )(?:قطعتين|قطعتان|حبتين)(?: |$)/u', $folded)) {
                return 2;
            }
        }

        return null;
    }

    /**
     * المحافظة باسمها أو بمركزها («الموصل»، «الحلة»)، والمنطقة من مناطقها: الأطول اسماً،
     * ثم الأسبق في الرسالة — وما جاء بعد «قرب» وأخواتها نقطةٌ دالّة لا منطقة («الكرادة قرب
     * ساحة كهرمانة»). وإن لم تُذكر المحافظة عُرفت من منطقتها، إلّا منطقةً يتكرّر اسمها في
     * أكثر من محافظة: تُترك ويُقال ذلك.
     *
     * @param array<int, string> $lines
     * @param ?int $title سطر رأس المحادثة
     * @return array{governorate: ?Governorate, city: ?object{id: int, governorate_id: int, name_ar: string}, warning: ?string,
     *     landmark: string, lines: list<int>}
     */
    private function address(array $lines, ?int $title = null): array
    {
        // سطر الاسم والملاحظة ورأس المحادثة ليست عنواناً، ولا سطرٌ كالاسم يبدأ باسمٍ أوّل: «ام علي»
        // اسمٌ وإن كانت «ال علي» منطقةً في النجف. والتحيّة ليست منطقة. وكلّ سطرٍ كلماتٌ مطويّة
        $words = [];
        foreach ($lines as $i => $line) {
            $words[$i] = $i === $title || preg_match(self::NAME_LABEL, $line) || preg_match(self::NOTE_LABEL, $line) || $this->isName($line)
                ? [] : $this->tokens((string) preg_replace(self::GREETINGS, ' ', $this->fold($line)));
        }
        $governorates = Governorate::offered()->get(['governorates.id', 'governorates.code', 'governorates.name_ar']);

        // المحافظة باسمها أو بمركزها، وبخطأ نقطةٍ أيضاً («تجف» هي «نجف»)
        [$governorate, $governorateLine] = [null, null];
        foreach ([false, true] as $loose) {
            foreach ($words as $i => $tokens) {
                foreach ($governorates as $candidate) {
                    foreach ($this->governorateNames($candidate) as $name) {
                        $size = substr_count($name, ' ') + 1;
                        for ($t = 0; $t + $size <= count($tokens); $t++) {
                            $said = implode(' ', array_slice($tokens, $t, $size));
                            if ($said === $name || ($loose && mb_strlen($name) >= 3 && $this->skeleton($said) === $this->skeleton($name))) {
                                [$governorate, $governorateLine] = [$candidate, $i];
                                break 5;
                            }
                        }
                    }
                }
            }
        }

        // مناطق المحافظة المذكورة، وإلّا فمناطق ما تخدمه الشركة من المحافظات — صفوفاً لا نماذج:
        // ستّة آلاف منطقة إن لم تُذكر المحافظة
        $cities = City::where('is_active', true)
            ->when($governorate, fn ($q) => $q->where('governorate_id', $governorate->id),
                fn ($q) => $q->whereIn('governorate_id', $governorates->modelKeys()))
            ->toBase()
            ->get(['id', 'governorate_id', 'name_ar']);
        // واسم أيّ محافظة، ولو أوقفتها الشركة: «طريق اربيل» في كركوك لا تُعرف بـ«اربيل»
        $generic = array_fill_keys([...self::GENERIC_WORDS, ...Governorate::all(['id', 'code', 'name_ar'])
            ->flatMap(fn (Governorate $g) => $this->governorateNames($g))->flatMap(fn (string $name) => explode(' ', $name))->all()], true);

        // كلمات كلّ منطقةٍ وما يميّزها منها، وفهرسٌ من الكلمة (وهيكلها بلا نقاط) إلى مناطقها
        $areas = [];
        $index = [];
        foreach ($cities as $c => $city) {
            $cityTokens = $this->tokens($city->name_ar);
            $own = array_keys(array_filter($cityTokens, fn (string $token) => ! isset($generic[$token])));
            // منطقةٌ لا اسم لها إلّا «حي» والمحافظة («حي بابل»، «الموصل»): باسمها كاملاً وحده
            $plain = array_filter($own, fn (int $k) => ! in_array($cityTokens[$k], self::WEAK_WORDS, true)) === [];
            $areas[$c] = ['city' => $city, 'tokens' => $cityTokens, 'own' => $own, 'plain' => $plain];
            if ($plain) {
                $index['='.$cityTokens[0]][] = [$c, 0];
                continue;
            }
            foreach ($own as $k) {
                $index[$cityTokens[$k]][] = [$c, $k];
                if (mb_strlen($cityTokens[$k]) >= 4) {
                    $index['~'.$this->skeleton($cityTokens[$k])][] = [$c, $k];
                }
            }
        }

        // كلّ سطرٍ: أطول ما يتّصل من كلماته بكلمات منطقة، ونصيبُ ما يميّزها منه
        $same = fn (string $a, string $b) => $a === $b || (mb_strlen($a) >= 4 && $this->skeleton($a) === $this->skeleton($b));
        $matches = [];
        foreach ($words as $i => $tokens) {
            foreach ($tokens as $p => $token) {
                $candidates = [...($index[$token] ?? []), ...(mb_strlen($token) >= 4 ? $index['~'.$this->skeleton($token)] ?? [] : [])];
                foreach ($index['='.$token] ?? [] as [$c]) {
                    $area = $areas[$c];
                    $length = count($area['tokens']);
                    if (array_slice($tokens, $p, $length) === $area['tokens']) {
                        $match = ['city' => $area['city'], 'line' => $i, 'from' => $p, 'length' => $length, 'said' => implode(' ', $area['tokens']),
                            'key' => implode(' ', $area['tokens']), 'whole' => true, 'exact' => true, 'fuzzy' => false, 'plain' => true, 'chars' => $length, 'span' => $length,
                            'prefix' => true, 'left' => 0, 'landmark' => array_intersect(array_slice($tokens, 0, $p), self::LANDMARK_TOKENS) !== [],
                            'tokens' => $area['tokens']];
                        $matches[$c.'@'.$i] ??= $match;
                    }
                }
                foreach ($candidates as [$c, $k]) {
                    $area = $areas[$c];
                    // «قرب» وأخواتها في الرسالة تبدأ نقطةً دالّة: لا تمتدّ المطابقة عبرها
                    $joins = fn (string $said, string $named) => ! in_array($said, self::LANDMARK_TOKENS, true) && $same($said, $named);
                    [$back, $ahead] = [0, 1];
                    while ($p - $back - 1 >= 0 && $k - $back - 1 >= 0 && $joins($tokens[$p - $back - 1], $area['tokens'][$k - $back - 1])) {
                        $back++;
                    }
                    while (isset($tokens[$p + $ahead], $area['tokens'][$k + $ahead]) && $joins($tokens[$p + $ahead], $area['tokens'][$k + $ahead])) {
                        $ahead++;
                    }
                    [$from, $to, $start] = [$p - $back, $p + $ahead, $k - $back];
                    $length = $to - $from;

                    // جهةٌ بعد ما تطابق ليست في اسم المنطقة: «الحمزة الشرقي» ليست «الحمزة الغربي» ولا «حمزة دلي»
                    $saidNext = $tokens[$to] ?? null;
                    if (in_array($saidNext, self::DIRECTIONS, true) && ! in_array($saidNext, $area['tokens'], true)) {
                        continue;
                    }

                    $covered = array_values(array_filter($area['own'], fn (int $o) => $o >= $start && $o < $start + $length));
                    if (array_filter($covered, fn (int $o) => ! in_array($area['tokens'][$o], self::WEAK_WORDS, true)) === []) {
                        continue;
                    }
                    // الجهة في اسم المنطقة لا تُطلب إن لم تُذكر: «الحمزة» تكفي لـ«الحمزة الغربي جنوب بابل»
                    $needed = array_filter($area['own'], fn (int $o) => in_array($o, $covered, true) || ! in_array($area['tokens'][$o], self::WEAK_WORDS, true));
                    $coverage = count($covered) / max(1, count($needed));
                    $chars = array_sum(array_map(fn (int $o) => mb_strlen($area['tokens'][$o]), $covered));
                    if ($coverage < 1 && ($coverage < 0.5 || $chars < 4)) {
                        continue;
                    }

                    $said = array_slice($tokens, $from, $length);
                    $first = array_key_first(array_filter($area['tokens'], fn (string $t) => ! isset($generic[$t]))) ?? 0;
                    $match = [
                        'city'     => $area['city'],
                        'line'     => $i,
                        'from'     => $from,
                        'length'   => $length,
                        'said'     => implode(' ', $said),
                        'key'      => implode(' ', array_map(fn (int $o) => $area['tokens'][$o], $covered)),
                        'whole'    => $coverage >= 1,
                        'exact'    => $start === 0 && $length === count($area['tokens']),
                        'fuzzy'    => $said !== array_slice($area['tokens'], $start, $length),
                        'plain'    => false,
                        'chars'    => $chars,
                        'span'     => array_sum(array_map('mb_strlen', $said)),
                        'prefix'   => $start <= $first,
                        'left'     => count($needed) - count($covered),
                        // ما بعد «قرب» وأخواتها نقطةٌ دالّة لا منطقة
                        'landmark' => array_intersect(array_slice($tokens, 0, $from), self::LANDMARK_TOKENS) !== [],
                        'tokens'   => $area['tokens'],
                    ];
                    $key = $c.'@'.$i;
                    if (! isset($matches[$key]) || $this->rank($match) < $this->rank($matches[$key])) {
                        $matches[$key] = $match;
                    }
                }
            }
        }

        $matches = array_values($matches);
        usort($matches, fn (array $a, array $b) => $this->rank($a) <=> $this->rank($b));
        $top = $matches[0] ?? null;
        $city = $top['city'] ?? null;

        // الاسم نفسه لأكثر من منطقة («الجزائر» في بغداد والبصرة، و«حي السلام» في «حي السلام /
        // الطوبجي» و«حي السلام / قرب حي الجهاد»): لا تخمين، والمحافظة إن كانت واحدة
        $warning = null;
        $rivals = $top ? array_filter($matches, fn (array $m) => $m['city']->id !== $city->id && $m['key'] === $top['key']
            && $this->rank($m, false) === $this->rank($top, false)) : [];
        if ($rivals !== []) {
            $oneGovernorate = collect($rivals)->every(fn (array $m) => $m['city']->governorate_id === $city->governorate_id);
            $governorate ??= $oneGovernorate ? $governorates->firstWhere('id', $city->governorate_id) : null;
            $label = $this->written($lines[$top['line']], $top['from'], $top['length']);
            $warning = $governorate
                ? '«'.$label.'» يطابق أكثر من منطقة في '.$governorate->name_ar.': اختر المنطقة.'
                : '«'.$label.'» منطقةٌ في أكثر من محافظة: اختر المحافظة ثم المنطقة.';
            $city = null;
        }

        $governorate ??= $city ? $governorates->firstWhere('id', $city->governorate_id) : null;
        $cityLine = $top['line'] ?? null;

        $addressLines = array_values(array_unique(array_filter([$governorateLine, $cityLine], fn ($l) => $l !== null)));
        [$landmark, $landmarkLines] = $this->landmark($lines, $addressLines,
            $governorate ? $this->governorateNames($governorate) : [], $top['said'] ?? null,
            $top ? array_map(fn (string $t) => $this->skeleton($t), $top['tokens']) : []);

        return ['governorate' => $governorate, 'city' => $city, 'warning' => $warning, 'landmark' => $landmark,
            'lines' => array_values(array_unique(array_merge($addressLines, $landmarkLines)))];
    }

    /**
     * ترتيب ما طابق من المناطق: لا بعد «قرب»، ثم ما يميّز الاسم كلّه، ثم بلا خطأ نقطة، ثم الأطول،
     * ثم الاسم بحروفه كلّها، ثم أوّل الاسم، ثم الأقلّ نقصاً — ثم الأسبق في الرسالة.
     *
     * @param array<string, mixed> $m
     * @return list<int|bool>
     */
    private function rank(array $m, bool $where = true): array
    {
        return [$m['landmark'], $m['plain'], ! $m['whole'], $m['fuzzy'], -$m['chars'], -$m['span'], ! $m['exact'], ! $m['prefix'], $m['left'],
            ...($where ? [$m['line'], $m['from']] : [])];
    }

    /** الكلمات كما كُتبت في الرسالة، ومعها «حي» أو «منطقة» قبلها: للتنبيه بالاسم كما كتبه الزبون */
    private function written(string $line, int $from, int $length): string
    {
        $source = preg_split('/[\s\-–،,:：|\/]+/u', trim((string) preg_replace(self::GREETINGS, ' ', $this->fold($line))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $original = preg_split('/[\s\-–،,:：|\/]+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($original) === count($source)) {
            $source = $original;
        }
        if ($from > 0 && in_array($this->matchFold($source[$from - 1]), ['حي', 'منطقا', 'محلا'], true)) {
            [$from, $length] = [$from - 1, $length + 1];
        }

        return implode(' ', array_slice($source, $from, $length));
    }

    /** @return list<string> كلمات النصّ مطويّةً للمطابقة كلمةً كلمة («الكرادة» → «كرادا») */
    private function tokens(string $text): array
    {
        $tokens = preg_split('/[\s\-–،,:：|\/]+/u', trim($this->fold($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map(fn (string $token) => $this->matchFold($token), $tokens), fn (string $t) => $t !== ''));
    }

    /**
     * هيكل الكلمة بلا نقاط: «الجمزة» و«الحمزة»، و«تجف» و«نجف» سواء — أكثر أخطاء الكتابة والقراءة
     * نقطةٌ زائدة أو ناقصة.
     */
    private function skeleton(string $word): string
    {
        return strtr($word, ['ب' => 'ٮ', 'ت' => 'ٮ', 'ث' => 'ٮ', 'ن' => 'ٮ', 'ي' => 'ٮ', 'ج' => 'ح', 'خ' => 'ح', 'ذ' => 'د',
            'ز' => 'ر', 'ش' => 'س', 'ض' => 'ص', 'ظ' => 'ط', 'غ' => 'ع', 'ق' => 'ف', 'ة' => 'ه']);
    }

    /** @return list<string> أسماء المحافظة مطويّةً: اسمها، وأسماء مراكزها كما تُكتب */
    private function governorateNames(Governorate $governorate): array
    {
        $aliases = array_filter(ShipmentSheet::GOVERNORATE_ALIASES[$governorate->code] ?? [],
            fn (string $alias) => preg_match('/\p{Arabic}/u', $alias) === 1);

        return array_values(array_unique(array_map(fn (string $name) => $this->matchFold($name),
            [$governorate->name_ar, ...$aliases])));
    }

    /**
     * ما بقي من سطر العنوان بعد المحافظة والمنطقة، وأسطرٌ تبدأ فيها «قرب» و«مقابل» وأخواتها —
     * بلا هاتفٍ ولا مبلغٍ ولا عددٍ إن كُتبت في السطر نفسه. وأرقام البيت باقية: «محلة 905 زقاق 12
     * دار 4» عنوانٌ كامل.
     *
     * @param array<int, string> $lines
     * @param list<int> $addressLines
     * @param list<string> $governorateNames
     * @param list<string> $cityWords كلمات اسم المنطقة مطويّةً: ما لاصق اسمها منها يُحذف معه
     * @return array{0: string, 1: list<int>}
     */
    private function landmark(array $lines, array $addressLines, array $governorateNames, ?string $cityNeedle, array $cityWords): array
    {
        $parts = [];
        $taken = [];
        // المنطقة أوّلاً: «دهوك مالطا» منطقةٌ باسمها كاملاً، لا «دهوك» ثم «مالطا»
        $names = array_values(array_unique(array_filter([$cityNeedle, ...$governorateNames])));
        $before = array_map(fn (string $word) => $this->skeleton($word), ['حي', 'منطقا', 'محلا', ...$cityWords]);
        $shape = fn (string $token) => $this->skeleton($this->matchFold($token));

        foreach ($lines as $i => $line) {
            $isAddress = in_array($i, $addressLines, true);
            if (! $isAddress && ! preg_match('/(?:^| )(?:'.self::LANDMARK_WORDS.')(?: |$)/u', $this->fold($line))) {
                continue;
            }
            if (! $isAddress && (preg_match(self::NAME_LABEL, $line) || preg_match(self::NOTE_LABEL, $line))) {
                continue;
            }

            $line = (string) preg_replace([self::PHONE, '/\d[\d,.،\s]*\s*(?:الف|ألف|الاف|آلاف|دينار|k)(?!\p{L})/iu',
                '/(?:العدد|عدد)\s*[:=\-]?\s*\d+/u', '/(?<!\d)\d{1,3}\s*(?:قطع|قطعه|قطعة|حبات|حبه|حبة)(?!\p{L})/u',
                '/(?:'.self::PRICE_WORDS.')\s*[:=\-]?\s*\d[\d,.،]*/u'], ' ', $line);
            $tokens = preg_split('/[\s\-–،,:：|\/]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            // كلّ اسمٍ يُحذف مرّةً في سطره، ومعه ما لاصقه من كلمات اسم المنطقة و«حي» قبله:
            // «حي الزهور قرب جامع الزهور» نقطتها «قرب جامع الزهور»، و«الصدر قطاع 39» لا شيء
            $keep = [];
            $removed = [];
            for ($t = 0; $t < count($tokens); $t++) {
                foreach ($names as $needle) {
                    $size = substr_count($needle, ' ') + 1;
                    $said = implode(' ', $this->tokens(implode(' ', array_slice($tokens, $t, $size))));
                    if (! isset($removed[$needle]) && ($said === $needle || $this->skeleton($said) === $this->skeleton($needle))) {
                        $removed[$needle] = true;
                        $t += $size - 1;
                        if ($needle === $cityNeedle) {
                            while ($keep !== [] && in_array($shape(end($keep)), $before, true)) {
                                array_pop($keep);
                            }
                            while (isset($tokens[$t + 1]) && in_array($shape($tokens[$t + 1]), $cityWords, true)) {
                                $t++;
                            }
                        }
                        continue 2;
                    }
                }
                if (! in_array($this->fold($tokens[$t]), self::ADDRESS_LABELS, true)) {
                    $keep[] = $tokens[$t];
                }
            }

            $rest = $this->trimValue(implode(' ', $keep));
            if (preg_match('/\p{Arabic}{2}/u', $rest)) {
                $parts[] = $rest;
                $taken[] = $i;
            }
        }

        return [mb_substr(implode('، ', array_unique($parts)), 0, 250), $taken];
    }

    /** سطرٌ كالاسم: كلمتان إلى أربع، حروفٌ عربية لا أرقام، ولا تحيّة ولا عنوان ولا مبلغ */
    private function looksLikeName(string $text): bool
    {
        $text = $this->trimValue($text);
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $folded = $this->fold($text);

        return count($words) >= 2 && count($words) <= 4
            && preg_match('/^[\p{Arabic}\s]+$/u', $text) === 1
            && min(array_map('mb_strlen', $words)) >= 2
            && preg_match(self::SMALL_TALK, $folded) === 0
            && preg_match('/(?:^| )(?:'.self::LANDMARK_WORDS.'|'.self::PRICE_WORDS.'|اريد|ابي|اطلب|عندي|رقمي|رقم|الرقم|موبايل)(?: |$)/u', $folded) === 0;
    }

    /**
     * سطرٌ قصيرٌ من رسالة الطلب يُكتب ملاحظة: «منقلة»، «توصيل مستعجل» — لا مبلغ ولا عدد ولا
     * تحيّة، ولا كلام محادثة («اريد اطلب…»، «شوكت تجون…»).
     */
    private function isNote(string $line): bool
    {
        $folded = $this->fold($line);
        $words = explode(' ', $folded);

        return count($words) <= 3 && preg_match('/\p{Arabic}{3}/u', $line) === 1 && ! preg_match('/\d/', $line)
            && preg_match(self::SMALL_TALK, $folded) === 0 && ! $this->isName($line)
            && preg_match('/(?:^| )(?:'.self::PRICE_WORDS.'|'.self::TOTAL_WORDS.'|الف|الاف|دينار|عدد|العدد|قطع|قطعه|قطعتين)(?: |$)/u', $folded) === 0
            && ! in_array($words[0], self::CHAT_WORDS, true);
    }

    /** سطرٌ كالاسم يبدأ باسمٍ أوّل معروف: «زينب كاظم» لا «شوكت تجون نطوني خبر» */
    private function isName(string $text): bool
    {
        return $this->looksLikeName($text) && FirstNames::starts($this->trimValue($text));
    }

    /** بلا مسافاتٍ وفواصل في طرفيه — trim() يقصّ بايتات فيقطع الحروف العربية من أوّلها */
    private function trimValue(string $value): string
    {
        $edges = '[\s.,،:;\-–_*\'"|]+';

        return (string) preg_replace(['/\s+/u', '/^'.$edges.'|'.$edges.'$/u'], [' ', ''], $value);
    }

    private function fold(string $text): string
    {
        return Arabic::fold(str_replace(['：', ':', '؛', ';', '|', '"', '«', '»'], ' ', $text));
    }

    /** للمبلغ: الحروف مطويّةً كما في fold، والنقطة والفاصلة باقيتان — «22.5 الف» لا «22 5 الف» */
    private function plain(string $text): string
    {
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و']);

        return mb_strtolower((string) preg_replace(['/[\x{064B}-\x{0652}\x{0640}]/u', '/\s+/u'], ['', ' '], $text));
    }

    /**
     * للمطابقة بالأسماء: مطويّاً بلا «ال» ولا «حي» (Arabic::looseFold)، وآخر الكلمة «ا» و«ة»
     * سواء: «عنكاوة» هي «عنكاوا» كما في القائمة.
     */
    private function matchFold(string $text): string
    {
        return (string) preg_replace('/ه(?= |$)/u', 'ا', Arabic::looseFold(str_replace(['：', ':', '؛', ';', '|', '"', '«', '»'], ' ', $text)));
    }
}
