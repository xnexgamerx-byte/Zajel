<?php

namespace App\Services\Settlements;

use App\Actions\Settlements\BuildCourierSettlement;
use App\Models\CourierSettlement;
use App\Models\Shipment;
use App\Support\Phone;
use App\Support\ScanCode;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * «مطابقة الوصولات» في كشف المندوب (docs/plan/124): ملفٌّ يرسله المندوب أو الشركة الوسيطة بما سلّمه — رقم الوصل
 * ورقم طلبه عندهم، والمبلغ والأجرة والحالة — يُطابَق بكشفه سطراً سطراً، فيُعرف ما يُحاسَب عليه بلا فرق، وما فيه فرق،
 * وما بالملف وليس بالكشف، وما بالكشف وليس بالملف.
 *
 * والأعمدة تُعرف من عناوينها لا من ترتيبها: «رقم طلب الشركة» أو «رقم الوصل» برقم وصلنا (وببادئة الوسيط
 * ZAJ_000310، docs/plan/109)، و«رقم الطلب» برقم طلبه عند الوسيط (aggregator_order_id).
 */
class CourierFileMatch
{
    /** أكبر ما يُقرأ من الملف: كشف أسبوعٍ لمندوبٍ كبير يبقى دونه */
    public const MAX_ROWS = 5000;

    /**
     * عناوين كلّ عمود كما تكتبها الأنظمة. الأدقّ أوّلاً: «رقم طلب الشركة» يُعرَف قبل «رقم الطلب»، فلا يخطف
     * رقمُ طلب الوسيط عمودَ رقم وصلنا.
     */
    public const HEADERS = [
        'number' => ['رقم طلب الشركة', 'رقم الوصل', 'رقم الشحنة', 'رقم وصل الشركة', 'الوصل', 'الباركود', 'waybill', 'barcode'],
        'order' => ['رقم الطلب', 'رقم الطلب عند الوسيط', 'order id', 'order'],
        'amount' => ['سعر الطلب', 'المبلغ', 'مبلغ الطلب', 'المبلغ المستلم', 'المحصل', 'المبلغ الكلي', 'السعر', 'amount'],
        'fee' => ['سعر التوصيل', 'اجرة التوصيل', 'اجور التوصيل', 'كلفة التوصيل', 'التوصيل', 'العمولة', 'fee'],
        'status' => ['حالة الطلب', 'الحالة', 'status'],
        'name' => ['اسم الزبون', 'اسم المستلم', 'الزبون', 'المستلم'],
        'phone' => ['رقم هاتف الزبون', 'هاتف الزبون', 'هاتف المستلم', 'الهاتف', 'phone'],
    ];

    /** حالةٌ في الملف تعني أنها لم تُسلَّم: الراجع والملغى والمؤجّل… وما سواها مع «تسليم» واصلة */
    private const NOT_DELIVERED = ['راجع', 'مرتجع', 'رجوع', 'الغاء', 'ملغي', 'ملغى', 'مؤجل', 'تاجيل', 'لم يتم', 'رفض', 'return', 'cancel'];

    private const DELIVERED = ['تسليم', 'سلم', 'واصل', 'مسلم', 'delivered'];

    /**
     * فئات المطابقة بترتيب عرضها: [الاسم، لون الشارة، شرحها]. «ok» وحدها يُحاسَب عليها، و«addable» يُضاف للكشف.
     */
    public const CATEGORIES = [
        'ok' => ['مطابقة', 'ok', 'بالكشف وبالملف بالمبلغ والأجرة نفسها.'],
        'amount' => ['فرق بالمبلغ', 'bad', 'المبلغ بالملف غير المحصَّل بالكشف.'],
        'fee' => ['فرق بالأجرة', 'warn', 'المبلغ نفسه، والأجرة بالملف غير عمولته بالكشف.'],
        'status' => ['غير واصلة بالملف', 'bad', 'بالكشف واصلة، وحالتها بالملف غير التسليم.'],
        'addable' => ['واصلة وليست بالكشف', 'info', 'واصلة عندنا لهذا المندوب ولم تُحاسَب: تُضاف للكشف.'],
        'settled' => ['محاسَبة سابقاً', 'mute', 'في كشفٍ آخر لهذا المندوب.'],
        'not_delivered' => ['غير واصلة عندنا', 'warn', 'عند هذا المندوب وحالتها عندنا غير التسليم.'],
        'other_courier' => ['عند مندوبٍ آخر', 'warn', 'الوصل عندنا لمندوبٍ غيره.'],
        'unknown' => ['غير موجودة عندنا', 'bad', 'لا وصل عندنا بهذا الرقم.'],
        'duplicate' => ['مكرّرة بالملف', 'mute', 'الوصل نفسه في سطرٍ قبله.'],
        'absent' => ['بالكشف وليست بالملف', 'warn', 'في الكشف ولم يذكرها الملف.'],
    ];

    public function __construct(protected BuildCourierSettlement $build) {}

    /**
     * سطور الملف بما يُطابَق به، بلا شيءٍ آخر منه.
     *
     * @return list<array{row:int, number:?string, order:?string, amount:?int, fee:?int, status:?string, delivered:?bool, name:?string, phone:?string}>
     */
    public function read(string $path): array
    {
        try {
            $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'تعذّرت قراءة الملف. ارفع ملف Excel أو CSV كما نزّلته.']);
        }

        // العناوين أوّل سطرٍ غير فارغ: بعض الأنظمة تكتب فوقها اسم التقرير
        $header = [];
        $skipped = 0;
        while ($rows && ! array_filter($header = array_map(fn ($c) => self::normalise((string) $c), array_shift($rows)))) {
            $skipped++; // سطرٌ فارغ قبل العناوين
        }

        $map = $this->mapHeader($header);

        if (! isset($map['number']) && ! isset($map['order'])) {
            throw ValidationException::withMessages([
                'file' => 'ما وجدنا في الملف عمود رقم الوصل («رقم طلب الشركة» أو «رقم الوصل») ولا «رقم الطلب».',
            ]);
        }

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'الملف أكبر من '.number_format(self::MAX_ROWS).' سطر: قسّمه.']);
        }

        $cell = fn (array $cells, string $field) => isset($map[$field]) ? trim((string) ($cells[$map[$field]] ?? '')) : '';
        $out = [];

        foreach ($rows as $i => $cells) {
            $number = $cell($cells, 'number');
            $order = self::digits($cell($cells, 'order'));

            if ($number === '' && $order === null) {
                continue; // سطرٌ فارغ أو مجموعٌ في التذييل
            }

            $scan = ScanCode::read($number);
            $status = $cell($cells, 'status');

            $out[] = [
                'row' => $i + 2 + $skipped,
                'number' => $number === '' || ! $scan->usable() ? null : $scan->code,
                'order' => $order === null ? null : (string) $order,
                'amount' => self::digits($cell($cells, 'amount')),
                'fee' => self::digits($cell($cells, 'fee')),
                'status' => $status === '' ? null : mb_substr($status, 0, 60),
                'delivered' => $status === '' ? null : self::delivered($status),
                'name' => mb_substr($cell($cells, 'name'), 0, 80) ?: null,
                'phone' => mb_substr($cell($cells, 'phone'), 0, 20) ?: null,
            ];
        }

        if (! $out) {
            throw ValidationException::withMessages(['file' => 'الملف بلا سطور وصولات.']);
        }

        return $out;
    }

    /** هل في الملف عمود الأجرة والحالة: ما ليس فيه لا يُقارَن */
    public static function has(array $rows, string $field): bool
    {
        return collect($rows)->contains(fn ($r) => $r[$field] !== null);
    }

    /**
     * يطابق سطور الملف بالكشف: لكلّ سطرٍ فئته وشحنته وسطر كشفه، ثم ما بالكشف وليس بالملف.
     *
     * @return array{items: Collection<int, array>, summary: array}
     */
    public function compare(CourierSettlement $settlement, array $rows): array
    {
        $courier = $settlement->courier;
        $lines = $settlement->lines()->get()->keyBy('shipment_id');

        $numbers = collect($rows)->pluck('number')->filter()->unique()->values();
        $orders = collect($rows)->pluck('order')->filter()->unique()->values();

        // بالرقم أو الباركود، وبرقم طلب الوسيط — قطعاً قطعاً فلا يطول الاستعلام
        $found = collect();
        foreach ($numbers->chunk(500) as $chunk) {
            $found = $found->merge(Shipment::query()->whereIn('number', $chunk->all())->get());
            $found = $found->merge(Shipment::query()->whereIn('barcode', $chunk->all())->get());
        }
        foreach ($orders->chunk(500) as $chunk) {
            $found = $found->merge(Shipment::query()->whereIn('aggregator_order_id', $chunk->all())->get());
        }
        $found = $found->unique('id')->keyBy('id');

        $byNumber = $found->keyBy('number');
        $byBarcode = $found->filter(fn ($s) => filled($s->barcode))->keyBy('barcode');
        $byOrder = $found->filter(fn ($s) => filled($s->aggregator_order_id))->keyBy('aggregator_order_id');

        $eligible = $found->isEmpty() ? collect() : $this->build->eligibleQuery($courier)
            ->whereIn('shipments.id', $found->keys()->all())->pluck('id')->flip();

        $compareFee = self::has($rows, 'fee');
        $seen = [];
        $items = collect();

        foreach ($rows as $row) {
            $shipment = ($row['order'] !== null ? $byOrder->get($row['order']) : null)
                ?? ($row['number'] !== null ? ($byNumber->get($row['number']) ?? $byBarcode->get($row['number'])) : null);

            $line = $shipment ? $lines->get($shipment->id) : null;

            $category = match (true) {
                ! $shipment => 'unknown',
                isset($seen[$shipment->id]) => 'duplicate',
                $line !== null => match (true) {
                    $row['delivered'] === false => 'status',
                    $row['amount'] !== null && $row['amount'] !== (int) $line->collected_amount => 'amount',
                    $compareFee && $row['fee'] !== null && $row['fee'] !== (int) $line->commission => 'fee',
                    default => 'ok',
                },
                (int) $shipment->delivery_courier_id !== (int) $courier->id => 'other_courier',
                $eligible->has($shipment->id) => 'addable',
                $shipment->courier_settlement_id !== null => 'settled',
                default => 'not_delivered',
            };

            if ($shipment) {
                $seen[$shipment->id] = true;
            }

            $items->push(['category' => $category, 'file' => $row, 'shipment' => $shipment, 'line' => $line]);
        }

        // ما بالكشف ولم يذكره الملف
        $absentIds = $lines->keys()->diff(array_keys($seen))->values();
        $absent = $absentIds->isEmpty() ? collect() : Shipment::query()->whereIn('id', $absentIds->all())->get()->keyBy('id');

        foreach ($absentIds as $id) {
            $items->push(['category' => 'absent', 'file' => null, 'shipment' => $absent->get($id), 'line' => $lines->get($id)]);
        }

        return ['items' => $items, 'summary' => $this->summary($items, $rows, $lines)];
    }

    /** الملخّص أعلى الصفحة: عدد كلّ فئةٍ ومبلغها، وما يُحاسَب عليه */
    protected function summary(Collection $items, array $rows, Collection $lines): array
    {
        $count = [];
        foreach (array_keys(self::CATEGORIES) as $key) {
            $count[$key] = $items->where('category', $key)->count();
        }

        $ok = $items->where('category', 'ok');

        return [
            'count' => $count,
            'file_rows' => count($rows),
            'file_amount' => (int) collect($rows)->sum('amount'),
            'file_fee' => (int) collect($rows)->sum('fee'),
            'draft_rows' => $lines->count(),
            'ok_amount' => (int) $ok->sum(fn ($i) => (int) $i['line']->collected_amount),
            'ok_fee' => (int) $ok->sum(fn ($i) => (int) $i['line']->commission),
            'ok_ids' => $ok->map(fn ($i) => $i['shipment']->id)->values()->all(),
            'addable_ids' => $items->where('category', 'addable')->map(fn ($i) => $i['shipment']->id)->values()->all(),
            // الفرق بين ما يقوله الملف وما يقوله الكشف، على ما بالاثنين
            'amount_diff' => (int) $items->whereIn('category', ['amount', 'fee', 'ok', 'status'])
                ->sum(fn ($i) => (int) ($i['file']['amount'] ?? $i['line']->collected_amount) - (int) $i['line']->collected_amount),
            'has_fee' => self::has($rows, 'fee'),
            'has_status' => self::has($rows, 'status'),
        ];
    }

    /** واصلة، أم غير واصلة، بحالتها بالملف — وما لا يُعرف null */
    public static function delivered(string $status): ?bool
    {
        $s = self::normalise($status);

        foreach (self::NOT_DELIVERED as $word) {
            if (str_contains($s, self::normalise($word))) {
                return false;
            }
        }

        foreach (self::DELIVERED as $word) {
            if (str_contains($s, self::normalise($word))) {
                return true;
            }
        }

        return null;
    }

    /** يربط عناوين الملف بالحقول: التامّة أوّلاً، ثم ما يحويه العنوان */
    protected function mapHeader(array $header): array
    {
        $map = [];
        $claimed = [];

        foreach ([true, false] as $exact) {
            foreach (self::HEADERS as $field => $labels) {
                if (isset($map[$field])) {
                    continue;
                }

                foreach ($labels as $label) {
                    $needle = self::normalise($label);

                    foreach ($header as $index => $cell) {
                        if ($cell === '' || in_array($index, $claimed, true)) {
                            continue;
                        }

                        if ($exact ? $cell === $needle : str_contains($cell, $needle)) {
                            $map[$field] = $index;
                            $claimed[] = $index;

                            continue 3;
                        }
                    }
                }
            }
        }

        return $map;
    }

    /** «20,000» و«٢٠٬٠٠٠ د.ع» ← 20000؛ وما بلا رقمٍ null */
    public static function digits(string $value): ?int
    {
        $digits = preg_replace('/\D/', '', Phone::latinDigits($value));

        return $digits === '' || strlen($digits) > 15 ? null : (int) $digits;
    }

    /** عنوانٌ أو حالة بلا تشكيلٍ ولا همزاتٍ ولا مسافاتٍ زائدة، فيُطابَق كما يُكتب */
    public static function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0640}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text);
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي', '_' => ' ']);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }
}
