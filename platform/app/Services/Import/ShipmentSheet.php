<?php

namespace App\Services\Import;

use App\Models\City;
use App\Models\Governorate;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * قراءة ملف شحنات التاجر والتحقّق منه صفّاً صفّاً.
 *
 * التاجر لا يُدخل مئة شحنة يدوياً، لكنه يرسل ملفاً فيه أخطاء دائماً:
 * محافظة مكتوبة بغير اسمها، هاتف بفاصلة، مبلغ بفواصل آلاف. فالمهمة
 * ليست القراءة بل أن يعرف أين الخطأ بالضبط في أي صفّ.
 */
class ShipmentSheet
{
    /** ترتيب الأعمدة كما يراه التاجر في القالب. */
    public const COLUMNS = [
        'recipient_name'      => 'اسم المستلم',
        'recipient_phone'     => 'هاتف المستلم',
        'recipient_phone_alt' => 'هاتف بديل',
        'governorate'         => 'المحافظة',
        'city'                => 'المنطقة',
        'address'             => 'العنوان',
        'landmark'            => 'أقرب نقطة دالّة',
        'cod_amount'          => 'المبلغ المطلوب',
        'pieces_count'        => 'عدد القطع',
        'weight_grams'        => 'الوزن بالغرام',
        'description'         => 'وصف المحتوى',
        'notes'               => 'ملاحظات للمندوب',
        'merchant_reference'  => 'رقم طلبك',
        'fees_paid_by'        => 'الأجرة على (التاجر/الزبون)',
    ];

    public const REQUIRED = ['recipient_name', 'recipient_phone', 'governorate', 'address', 'landmark', 'cod_amount'];

    /** @return Collection<int, array{row:int, data:array, errors:array<string>}> */
    public function read(string $path): Collection
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);

        $header = array_map(
            fn ($cell) => $this->normalise((string) $cell),
            array_shift($rows) ?? []
        );

        $map = $this->mapHeader($header);
        $governorates = Governorate::where('is_active', true)->get();
        $cities = City::where('is_active', true)->get();

        // حلقة صريحة لا map: الدالّة السهمية تلتقط $seenReferences بالقيمة،
        // فيبدأ كل صفّ بسجلّ فارغ ولا يُكتشف تكرار قطّ.
        $seenReferences = [];
        $parsed = [];

        foreach ($rows as $i => $cells) {
            $row = $this->parseRow($cells, $i + 2, $map, $governorates, $cities, $seenReferences);

            if ($row !== null) {
                $parsed[] = $row;
            }
        }

        return collect($parsed);
    }

    /** يربط عناوين الملف بالحقول، فترتيب الأعمدة لا يُلزم التاجر. */
    protected function mapHeader(array $header): array
    {
        $map = [];
        $claimed = [];

        // المطابقة التامّة أولاً: عمود اسمه «هاتف بديل» يخصّ حقله،
        // ولا يخطفه «هاتف المستلم» لمجرّد أن أحدهما يحوي الآخر.
        foreach ([true, false] as $exact) {
            foreach (self::COLUMNS as $field => $label) {
                if (isset($map[$field])) {
                    continue;
                }

                $needle = $this->normalise($label);

                foreach ($header as $index => $cell) {
                    if ($cell === '' || in_array($index, $claimed, true)) {
                        continue;
                    }

                    $hit = $exact
                        ? $cell === $needle
                        : str_contains($needle, $cell) || str_contains($cell, $needle);

                    if ($hit) {
                        $map[$field] = $index;
                        $claimed[] = $index;
                        break;
                    }
                }
            }
        }

        return $map;
    }

    protected function parseRow(
        array $cells,
        int $rowNumber,
        array $map,
        Collection $governorates,
        Collection $cities,
        array &$seenReferences,
    ): ?array {
        $value = function (string $field) use ($cells, $map) {
            $index = $map[$field] ?? null;

            return $index === null ? '' : trim((string) ($cells[$index] ?? ''));
        };

        // صفّ فارغ تماماً: تذييل الملف عادةً، يُتجاهل بلا خطأ
        if (collect(array_keys(self::COLUMNS))->every(fn ($f) => $value($f) === '')) {
            return null;
        }

        $errors = [];
        $data = [];

        foreach (self::REQUIRED as $field) {
            if ($value($field) === '') {
                $errors[] = self::COLUMNS[$field].' مطلوب';
            }
        }

        $data['recipient_name'] = mb_substr($value('recipient_name'), 0, 160);
        $data['address'] = mb_substr($value('address'), 0, 500);
        $data['landmark'] = mb_substr($value('landmark'), 0, 255);
        $data['description'] = mb_substr($value('description'), 0, 2000) ?: null;
        $data['notes'] = mb_substr($value('notes'), 0, 2000) ?: null;
        $data['merchant_reference'] = mb_substr($value('merchant_reference'), 0, 60) ?: null;

        foreach (['recipient_phone', 'recipient_phone_alt'] as $field) {
            $phone = $this->normalisePhone($value($field));

            if ($phone === null) {
                if ($value($field) !== '') {
                    $errors[] = self::COLUMNS[$field].' غير صحيح (يبدأ بـ 07 و11 رقماً)';
                }

                $data[$field] = null;

                continue;
            }

            $data[$field] = $phone;
        }

        $governorate = $this->matchGovernorate($value('governorate'), $governorates);

        if ($value('governorate') !== '' && ! $governorate) {
            $errors[] = 'المحافظة «'.$value('governorate').'» غير معروفة';
        }

        $data['governorate_id'] = $governorate?->id;
        $data['city_id'] = null;

        if ($governorate && $value('city') !== '') {
            $city = $cities->first(fn (City $c) => $c->governorate_id === $governorate->id
                && $this->normalise($c->name_ar) === $this->normalise($value('city')));

            if (! $city) {
                $errors[] = 'المنطقة «'.$value('city').'» ليست في '.$governorate->name_ar;
            }

            $data['city_id'] = $city?->id;
        }

        $data['cod_amount'] = $this->toInt($value('cod_amount'));

        if ($value('cod_amount') !== '' && $data['cod_amount'] === null) {
            $errors[] = 'المبلغ غير صحيح';
        }

        $data['cod_amount'] ??= 0;
        $data['pieces_count'] = max(1, $this->toInt($value('pieces_count')) ?? 1);
        $data['weight_grams'] = max(0, $this->toInt($value('weight_grams')) ?? 0);

        $feesPaidBy = $this->normalise($value('fees_paid_by'));
        $data['fees_paid_by'] = str_contains($feesPaidBy, 'زبون') || str_contains($feesPaidBy, 'مستلم')
            ? 'customer'
            : 'merchant';

        // تكرار رقم الطلب داخل الملف نفسه — أكثر خطأ يمرّ بلا انتباه
        if ($data['merchant_reference']) {
            if (isset($seenReferences[$data['merchant_reference']])) {
                $errors[] = 'رقم طلبك مكرّر مع الصفّ '.$seenReferences[$data['merchant_reference']];
            } else {
                $seenReferences[$data['merchant_reference']] = $rowNumber;
            }
        }

        return ['row' => $rowNumber, 'data' => $data, 'errors' => $errors];
    }

    /** يقبل 07xx، +9647xx، و07xx بفواصل أو مسافات. */
    protected function normalisePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $this->toLatinDigits($raw));

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '964')) {
            $digits = '0'.substr($digits, 3);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '7')) {
            $digits = '0'.$digits;
        }

        return preg_match('/^07[0-9]{9}$/', $digits) ? $digits : null;
    }

    protected function matchGovernorate(string $raw, Collection $governorates): ?Governorate
    {
        if ($raw === '') {
            return null;
        }

        $needle = $this->normalise($raw);

        return $governorates->first(fn (Governorate $g) => $this->normalise($g->name_ar) === $needle
            || $this->normalise($g->name_en) === $needle
            || strtolower($g->code) === strtolower($raw))
            // أسماء شائعة تُكتب بدل اسم المحافظة
            ?? $governorates->first(fn (Governorate $g) => match ($needle) {
                'الموصل'                => $g->code === 'NIN',
                'الحله', 'الحلة'        => $g->code === 'BBL',
                'الرمادي'               => $g->code === 'ANB',
                'الديوانيه', 'الديوانية' => $g->code === 'QAD',
                'الناصريه', 'الناصرية'  => $g->code === 'DHQ',
                'العماره', 'العمارة'    => $g->code === 'MYS',
                'الكوت'                 => $g->code === 'WST',
                'السماوه', 'السماوة'    => $g->code === 'MTH',
                'بعقوبه', 'بعقوبة'      => $g->code === 'DYL',
                'تكريت'                 => $g->code === 'SAL',
                default                 => false,
            });
    }

    protected function toInt(string $raw): ?int
    {
        $clean = preg_replace('/[^\d.\-]/', '', $this->toLatinDigits($raw));

        return $clean === '' || ! is_numeric($clean) ? null : (int) round((float) $clean);
    }

    /** الأرقام العربية الشرقية تصل من بعض الملفات كما هي. */
    protected function toLatinDigits(string $raw): string
    {
        return strtr($raw, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    /** يوحّد الهمزات والتاء المربوطة والمسافات — أسماء المحافظات تُكتب بصور شتّى. */
    protected function normalise(string $raw): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $raw));
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);
        $text = preg_replace('/[\x{064B}-\x{0652}]/u', '', $text);

        return mb_strtolower(trim(str_replace(['ـ', '-', '_', '*'], '', $text)));
    }

    /** قالب جاهز بأسماء المحافظات في قائمة منسدلة. */
    public function template(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle('الشحنات');

        $column = 'A';

        foreach (self::COLUMNS as $field => $label) {
            $sheet->setCellValue($column.'1', $label.(in_array($field, self::REQUIRED, true) ? ' *' : ''));
            $sheet->getColumnDimension($column)->setWidth(in_array($field, ['address', 'landmark'], true) ? 32 : 18);
            $column++;
        }

        $last = chr(ord('A') + count(self::COLUMNS) - 1);
        $header = $sheet->getStyle('A1:'.$last.'1');
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E7E5E4');
        $header->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A2');

        // صفّ مثال يوضّح الصيغة المتوقّعة أكثر من أي شرح
        $sheet->fromArray([
            'علي حسين', '07801234567', '', 'بغداد', 'الكرادة',
            'بغداد - الكرادة، شارع 62', 'مقابل جامع الشيخ معروف',
            50000, 1, 1500, 'ملابس', 'اتصل قبل الوصول', 'ORD-1001', 'التاجر',
        ], null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'zajel-template-').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }
}
