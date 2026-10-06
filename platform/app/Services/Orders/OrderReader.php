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
    private const LANDMARK_WORDS = 'قرب|مقابل|خلف|جنب|يم|بجانب|شارع|زقاق|محله|دار|عماره|مجمع|تقاطع|ساحه|جامع|مسجد|مدرسه|مستشفي|سوق|بنايه';

    /** تحيّةٌ وردٌّ وعنوان محادثة: ليست اسماً — مطويّةً */
    private const SMALL_TALK = '/^(?:السلام عليكم|سلام عليكم|مرحبا|هلا|اهلا|هلو|هاي|شكرا|تمام|اوكي|اوك|ok|okay|نعم|اي|زين|حاضر|وصل طلبك|طلب جديد|زبون جديد)(?:\s|$)/u';

    /**
     * رسائلُ منسوخةٌ معاً من واتساب تبدأ بوقتها ومُرسلها: «[10:12 م، 5/10/2026] علي: …»،
     * أو في المحادثة المصدَّرة «5/10/26، 10:12 م - علي: …». والمُرسل اسمه المحفوظ أو رقمه.
     */
    private const CHAT_PREFIX = '/^\s*(?:\[[^\]]{4,40}\]|\d{1,4}[\/.\-]\d{1,2}[\/.\-]\d{1,4}[,،]?\s+\d{1,2}[:.]\d{2}(?:\s*(?:[AaPp]\.?[Mm]\.?|[مص]))?\s+[\-–])\s*(?<sender>[^:]{1,40}?)\s*:\s/u';

    /** تحيّاتٌ فيها أسماء مناطق («حي السلام»، «حي النور») — مطويّةً، تُحذف قبل البحث عن المنطقة */
    private const GREETINGS = '/(?<=^| )(?:السلام عليكم|سلام عليكم|وعليكم السلام|عليكم السلام|صباح الخير|صباح النور|مساء الخير|مساء النور|مرحبا|اهلا|اهلين|هلا|الله يسلمك|الله يحفظك|حياك الله|يعطيك العافيه|الحمد لله|شكرا|مشكور|تسلم)(?= |$)/u';

    /** علامة الوقت بعد الساعة: م/ص، أو AM/PM */
    private const MERIDIEM = '(?:[مص]|[AaPp]\.?[Mm]\.?)';

    /** عناوين حقولٍ تُكتب قبل العنوان — مطويّةً، تُحذف من النقطة الدالّة */
    private const ADDRESS_LABELS = ['العنوان', 'عنوان', 'المحافظه', 'محافظه', 'المنطقه', 'منطقه', 'اقرب', 'نقطه', 'داله'];

    public function __construct(private readonly ScreenshotText $screenshots) {}

    /** @return Reading */
    public function fromImage(string $path): array
    {
        return $this->fromText($this->screenshots->read($path));
    }

    /** @return Reading */
    public function fromText(string $text): array
    {
        [$lines, $senders] = $this->clean($text);
        $fields = [];
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

        if ($price = $this->price($lines)) {
            $fields['cod_amount'] = $price;
            $found['cod_amount'] = number_format($price).' د.ع';
        }

        if ($pieces = $this->pieces($lines)) {
            $fields['pieces_count'] = $pieces;
        }

        // العنوان: المحافظة والمنطقة من قوائم الشركة، وما بقي من سطرهما نقطةٌ دالّة
        $address = $this->address($lines);
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

        // الاسم والملاحظة بعنوانيهما، وإلّا فالاسم سطرٌ كالاسم بجانب الهاتف
        foreach ($lines as $line) {
            if (! isset($fields['notes']) && preg_match(self::NOTE_LABEL, $line, $m)) {
                $fields['notes'] = $this->trimValue($m[1]);
            } elseif (! isset($fields['recipient_name']) && preg_match(self::NAME_LABEL, $line, $m) && $this->looksLikeName($m[1])) {
                $fields['recipient_name'] = $this->trimValue($m[1]);
            }
        }
        if (! isset($fields['recipient_name']) && $phones !== []) {
            foreach ([$phones[0]['line'] - 1, $phones[0]['line'] + 1] as $i) {
                if (isset($lines[$i]) && ! in_array($i, $address['lines'], true) && $this->looksLikeName($lines[$i])) {
                    $fields['recipient_name'] = $this->trimValue($lines[$i]);
                    break;
                }
            }

            // وإلّا فاسم مُرسل الرقم في واتساب كما حفظه التاجر
            $sender = $senders[$phones[0]['line']] ?? null;
            if (! isset($fields['recipient_name']) && $sender !== null && $this->looksLikeName($sender)) {
                $fields['recipient_name'] = $this->trimValue($sender);
            }
        }
        if (isset($fields['recipient_name'])) {
            $found['recipient_name'] = $fields['recipient_name'];
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
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function clean(string $text): array
    {
        $text = Phone::latinDigits((string) preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text));
        $text = strtr($text, ['٫' => '.', '٬' => ',']);
        $lines = [];
        $senders = [];
        $sender = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match(self::CHAT_PREFIX, $line, $m)) {
                $sender = $this->trimValue($m['sender']);
                $line = mb_substr($line, mb_strlen($m[0]));
            }

            // وقت الرسالة: «10:42 م» في آخر الفقاعة، أو ما بقي منه بعد القراءة («2 م»). وبنقطةٍ
            // («10.42 م») وقتٌ بعلامة م أو ص وحدها: «السعر 22.50 الف» مبلغ
            $line = (string) preg_replace('/(^|\s)(?:\d{1,2}:\d{2}(?:\s*'.self::MERIDIEM.')?|\d{1,2}\.\d{2}\s*'.self::MERIDIEM.')(?=\s|$)/u', ' ', $line);
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));

            if ($line === '' || preg_match('/^\d{0,4}\s*[مص]{1,2}$/u', $line) || ! preg_match('/[\p{L}\d]{2}/u', $line)) {
                continue;
            }

            $lines[] = $line;
            if ($sender !== null) {
                $senders[array_key_last($lines)] = $sender;
            }
        }

        return [$lines, $senders];
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
     */
    private function price(array $lines): ?int
    {
        // الرقم كاملاً لا بعضه: «25 الف» لا تُقرأ «2» بعد كلمة السعر
        $number = '(\d{1,3}(?:[,.،\s]\d{3})+|\d+(?:[.,]\d{1,2})?)(?![\d.,،])';
        $best = null;
        $lone = null;

        foreach ($lines as $line) {
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
            if ($candidates === [] && preg_match('/^'.$number.'$/u', trim($folded), $m)
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
     * @return array{governorate: ?Governorate, city: ?object{id: int, governorate_id: int, name_ar: string}, warning: ?string,
     *     landmark: string, lines: list<int>}
     */
    private function address(array $lines): array
    {
        // سطرا الاسم والملاحظة ليسا عنواناً («علي حسين»، و«حي الحسين» منطقة)، والتحيّة ليست منطقة
        $folded = array_map(fn (string $line) => preg_match(self::NAME_LABEL, $line) || preg_match(self::NOTE_LABEL, $line)
            ? '' : ' '.$this->matchFold((string) preg_replace(self::GREETINGS, ' ', $this->fold($line))).' ', $lines);
        $governorates = Governorate::offered()->get(['governorates.id', 'governorates.code', 'governorates.name_ar']);

        $governorate = null;
        $governorateLine = null;
        foreach ($folded as $i => $line) {
            foreach ($governorates as $candidate) {
                foreach ($this->governorateNames($candidate) as $needle) {
                    if (str_contains($line, ' '.$needle.' ')) {
                        [$governorate, $governorateLine] = [$candidate, $i];
                        break 3;
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
        $governorateNames = $governorate ? $this->governorateNames($governorate) : [];
        $anyGovernorate = $governorates->flatMap(fn (Governorate $g) => $this->governorateNames($g))->all();
        $landmarkWord = '/ (?:'.self::LANDMARK_WORDS.') /u';

        // الأسطر نصّاً واحداً يُبحث فيه مرّةً لكل اسم، وكل سطرٍ بين مسافتين فلا يعبر اسمٌ سطرين
        $haystack = implode("\n", $folded);
        $matches = [];
        foreach ($cities as $city) {
            foreach ($this->cityNeedles($city->name_ar) as [$needle, $label, $segment]) {
                if ($segment && in_array($needle, $anyGovernorate, true)) {
                    continue;   // «بغداد / حي عامل» لا تُعرف بـ«بغداد»
                }
                $at = strpos($haystack, ' '.$needle.' ');
                if ($at === false) {
                    continue;
                }
                $start = strrpos(substr($haystack, 0, $at), "\n");
                $start = $start === false ? 0 : $start + 1;
                $matches[] = [
                    'city'      => $city,
                    'needle'    => $needle,
                    'label'     => $label,
                    'segment'   => $segment,
                    'line'      => substr_count($haystack, "\n", 0, $at),
                    'at'        => $at,
                    // «الموصل» منطقةٌ في نينوى واسمٌ لها: ذكرُ المحافظة لا المنطقة
                    'isRegion'  => in_array($needle, $governorateNames, true),
                    'landmark'  => preg_match($landmarkWord, substr($haystack, $start, $at - $start + 1)) === 1,
                ];
                continue 2;
            }
        }

        if (count($matches) > 1) {
            $matches = array_values(array_filter($matches, fn (array $m) => ! $m['isRegion'])) ?: $matches;
        }
        // الأطول أدقّ («قطاع 39» قبل «حي الصدر»)، وعند التساوي الاسم كاملاً قبل جزء اسمٍ آخر
        $rank = fn (array $m) => [$m['landmark'], -mb_strlen($m['needle']), $m['segment'], $m['line'], $m['at']];
        usort($matches, fn (array $a, array $b) => $rank($a) <=> $rank($b));

        $top = $matches[0] ?? null;
        $city = $top['city'] ?? null;

        // الاسم نفسه لأكثر من منطقة («الجزائر» في بغداد والبصرة، و«حي السلام» في «حي السلام /
        // الطوبجي» و«حي السلام / قرب حي الجهاد»): لا تخمين، والمحافظة إن كانت واحدة
        $warning = null;
        $rivals = $top ? array_filter($matches, fn (array $m) => $m['needle'] === $top['needle']
            && $m['segment'] === $top['segment'] && $m['landmark'] === $top['landmark'] && $m['city']->id !== $city->id) : [];
        if ($rivals !== []) {
            $oneGovernorate = collect($rivals)->every(fn (array $m) => $m['city']->governorate_id === $city->governorate_id);
            $governorate ??= $oneGovernorate ? $governorates->firstWhere('id', $city->governorate_id) : null;
            $warning = $governorate
                ? '«'.$top['label'].'» يطابق أكثر من منطقة في '.$governorate->name_ar.': اختر المنطقة.'
                : '«'.$top['label'].'» منطقةٌ في أكثر من محافظة: اختر المحافظة ثم المنطقة.';
            $city = null;
        }

        $governorate ??= $city ? $governorates->firstWhere('id', $city->governorate_id) : null;
        $cityLine = $top['line'] ?? null;
        $needle = $top['needle'] ?? null;

        $addressLines = array_values(array_unique(array_filter([$governorateLine, $cityLine], fn ($l) => $l !== null)));
        $cityWords = $top ? array_values(array_filter(array_map(fn (string $word) => $this->matchFold($word),
            preg_split('/[\s\-–\/]+/u', $top['city']->name_ar, -1, PREG_SPLIT_NO_EMPTY) ?: []))) : [];
        [$landmark, $landmarkLines] = $this->landmark($lines, $addressLines,
            $governorate ? $this->governorateNames($governorate) : [], $needle, $cityWords);

        return ['governorate' => $governorate, 'city' => $city, 'warning' => $warning, 'landmark' => $landmark,
            'lines' => array_values(array_unique(array_merge($addressLines, $landmarkLines)))];
    }

    /**
     * ما تُعرف به المنطقة في الرسالة: اسمها كاملاً، ثم كلّ جزءٍ من اسمٍ مركّب — «حي السلام /
     * الطوبجي» تُعرف بـ«الطوبجي»، و«مدينة الصدر - قطاع 39» بـ«قطاع 39».
     *
     * @return list<array{0: string, 1: string, 2: bool}> [مطويّاً، كما يُعرض، جزءٌ أم الاسم كاملاً]
     */
    private function cityNeedles(string $name): array
    {
        $needles = [[$this->matchFold($name), $name, false]];

        $parts = preg_split('/\s*\/\s*|\s+[\-–]\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) > 1) {
            foreach ($parts as $part) {
                $needles[] = [$this->matchFold($part), trim($part), true];
            }
        }

        return array_values(array_filter($needles, fn (array $needle) => mb_strlen($needle[0]) >= 3));
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
        $before = ['حي', 'منطقا', 'محلا', ...$cityWords];

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
                    if (! isset($removed[$needle]) && $this->matchFold(implode(' ', array_slice($tokens, $t, $size))) === $needle) {
                        $removed[$needle] = true;
                        $t += $size - 1;
                        if ($needle === $cityNeedle) {
                            while ($keep !== [] && in_array($this->matchFold(end($keep)), $before, true)) {
                                array_pop($keep);
                            }
                            while (isset($tokens[$t + 1]) && in_array($this->matchFold($tokens[$t + 1]), $cityWords, true)) {
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
