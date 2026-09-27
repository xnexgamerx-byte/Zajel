<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * ملف Excel يُكتب صفّاً صفّاً إلى القرص.
 *
 * PhpSpreadsheet يبني الورقة كلّها في الذاكرة — قرابة كيلوبايت للخلية — فقائمة
 * عشرين ألف شحنة بستة عشر عموداً تطلب ثلاثمئة ميغابايت، وحدّ الخادم ٢٥٦. وملف
 * xlsx ليس إلّا ملفّات XML في ملفٍّ مضغوط: الورقة تُكتب سطراً سطراً، فتبقى الذاكرة
 * ثابتة مهما طالت القائمة.
 *
 * يمين إلى يسار، والعناوين عريضة ومثبّتة أعلى الورقة، والأرقام بفواصل الآلاف،
 * والنصّ نصٌّ: «07701234567» لا يصير 7701234567.
 */
final class StreamingXlsx
{
    /** @var resource */
    private $sheet;

    private string $sheetPath;

    private int $rows = 0;

    /**
     * @param  list<array{0: string, 1: int, 2?: string}>  $columns  [العنوان، العرض، 'number' للأرقام]
     */
    public function __construct(private array $columns, private string $title = 'الورقة')
    {
        $this->sheetPath = tempnam(sys_get_temp_dir(), 'xlsx-sheet-');
        $this->sheet = fopen($this->sheetPath, 'wb');

        $cols = '';
        foreach ($columns as $i => $column) {
            $n = $i + 1;
            $cols .= '<col min="'.$n.'" max="'.$n.'" width="'.(int) $column[1].'" customWidth="1"/>';
        }

        fwrite($this->sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView rightToLeft="1" workbookViewId="0">'
            .'<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            .'</sheetView></sheetViews>'
            .'<cols>'.$cols.'</cols><sheetData>');

        $this->row(array_column($columns, 0), header: true);
    }

    /** @param  list<string|int|float|null>  $values */
    public function add(array $values): void
    {
        $this->row($values);
    }

    public function count(): int
    {
        return $this->rows - 1;
    }

    /** يغلق الورقة ويجمع الملف في $path. */
    public function save(string $path): void
    {
        fwrite($this->sheet, '</sheetData></worksheet>');
        fclose($this->sheet);

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("تعذّر إنشاء {$path}");
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->escape($this->sheetName()).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');

        // ٠ عادي، ١ عنوانٌ عريض على رماديّ، ٢ رقمٌ بفواصل الآلاف (الصيغة المبنيّة ٣)
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF1F0EE"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs></styleSheet>');

        $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');

        if (! $zip->close()) {
            throw new RuntimeException("تعذّر حفظ {$path}");
        }

        @unlink($this->sheetPath);
    }

    /** @param  list<string|int|float|null>  $values */
    private function row(array $values, bool $header = false): void
    {
        $this->rows++;
        $r = $this->rows;
        $xml = '<row r="'.$r.'">';

        foreach (array_values($values) as $i => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $ref = self::column($i).$r;
            $numeric = ! $header && ($this->columns[$i][2] ?? null) === 'number' && is_numeric($value);

            $xml .= $numeric
                ? '<c r="'.$ref.'" s="2"><v>'.(0 + $value).'</v></c>'
                : '<c r="'.$ref.'" t="inlineStr"'.($header ? ' s="1"' : '').'><is><t xml:space="preserve">'
                    .$this->escape((string) $value).'</t></is></c>';
        }

        fwrite($this->sheet, $xml.'</row>');
    }

    /** A، B، … Z، AA، AB … */
    public static function column(int $index): string
    {
        $name = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    private function escape(string $text): string
    {
        // ما لا يقبله XML من محارف التحكّم يسقط، وإلّا رفض Excel الملفّ كلّه
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** اسم الورقة: ٣١ محرفاً بلا ما يمنعه Excel */
    private function sheetName(): string
    {
        $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $this->title));

        return mb_substr($name !== '' ? $name : 'الورقة', 0, 31);
    }
}
