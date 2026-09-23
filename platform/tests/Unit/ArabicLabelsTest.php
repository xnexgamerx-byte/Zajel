<?php

namespace Tests\Unit;

use App\Enums\ShipmentStatus;
use App\Models\AuditLog;
use App\Models\ShipmentEvent;
use App\Models\Transaction;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ما يُكتب في القاعدة معرّفاً يُقرأ على الشاشة بالعربية.
 *
 * التسويتان تكتبان في to_status علامةً لا حالة، وكانت صفحة الشحنة تعرضها
 * كما خُزِّنت: «settled_with_courier» بين «تم الاستلام» و«تم التسليم».
 * وسجلّ المنصّة عرض «company_settings_updated» لأن قائمة أسمائه كُتبت
 * قبل الفعل.
 */
class ArabicLabelsTest extends TestCase
{
    public function test_a_settlement_marker_reads_in_arabic(): void
    {
        $this->assertSame('سُوّيت مع المندوب', (new ShipmentEvent(['to_status' => 'settled_with_courier']))->toLabel());
        $this->assertSame('سُوّيت مع التاجر', (new ShipmentEvent(['to_status' => 'settled_with_merchant']))->toLabel());
        $this->assertSame(ShipmentStatus::Delivered->label(), (new ShipmentEvent(['to_status' => 'delivered']))->toLabel());
    }

    /** كل to_status حرفيّ في الشيفرة: حالةٌ معروفة أو علامةٌ لها اسم. */
    public function test_every_marker_written_by_the_code_has_an_arabic_name(): void
    {
        $unnamed = [];
        $checked = 0;

        foreach (File::allFiles(app_path()) as $file) {
            preg_match_all("/'to_status'\\s*=>\\s*'([a-z_]+)'/", $file->getContents(), $matches);

            foreach ($matches[1] as $value) {
                $checked++;

                if (! ShipmentStatus::tryFrom($value) && ! isset(ShipmentEvent::MARKERS[$value])) {
                    $unnamed[] = $file->getRelativePathname()." — {$value}";
                }
            }
        }

        $this->assertGreaterThanOrEqual(2, $checked);
        $this->assertSame([], $unnamed, "علامة to_status بلا اسمٍ عربي:\n".implode("\n", $unnamed));
    }

    /** كل فعلٍ في سجلّ التدقيق تكتبه الشيفرة له اسمٌ عربي. */
    public function test_every_audit_action_written_by_the_code_has_an_arabic_name(): void
    {
        $unnamed = [];
        $checked = 0;

        foreach (File::allFiles(app_path()) as $file) {
            if (! str_contains($file->getContents(), 'AuditLog::create')) {
                continue;
            }

            preg_match_all("/'action'\\s*=>\\s*'([a-z_]+)'/", $file->getContents(), $matches);

            foreach ($matches[1] as $action) {
                $checked++;

                if (! isset(AuditLog::ACTIONS[$action])) {
                    $unnamed[] = $file->getRelativePathname()." — {$action}";
                }
            }
        }

        $this->assertGreaterThanOrEqual(8, $checked);
        $this->assertSame([], $unnamed, "فعلٌ في سجلّ التدقيق بلا اسمٍ عربي:\n".implode("\n", $unnamed));
        $this->assertSame('عُدِّلت بيانات الشركة', (new AuditLog(['action' => 'company_settings_updated']))->actionLabel());
    }

    /** كل نوع قيدٍ يكتبه الدفتر له اسمٌ عربي. */
    public function test_every_ledger_category_has_an_arabic_name(): void
    {
        preg_match_all("/category:\\s*'([a-z_]+)'/", File::get(app_path('Services/Ledger.php')), $matches);

        $this->assertGreaterThanOrEqual(8, count(array_unique($matches[1])));
        $this->assertSame([], array_values(array_diff(array_unique($matches[1]), array_keys(Transaction::CATEGORIES))));
        $this->assertSame('تصحيح مبلغ', (new Transaction(['category' => 'amount_correction']))->categoryLabel());
    }
}
