<?php

namespace App\Enums;

/**
 * ميزات النظام التي يفتحها صاحب المنصّة لكل شركةٍ أو يغلقها، مجّاناً أو برسمٍ شهريّ
 * على فاتورتها (docs/plan/35). وكل شاشات الميزة تحمل وسيط feature:<المفتاح>، فلا تُفتح
 * ولا يظهر رابطها في شركةٍ أُغلقت فيها.
 *
 * قاعدةٌ بلا استثناء: الميزة الجديدة مطفأةٌ في كل الشركات حتى تُفتح لشركةٍ بعينها —
 * فلا تُضاف إلى INCLUDED. وما في INCLUDED كان في النظام قبل هذه القائمة: يبقى مفتوحاً
 * لكل شركة كما كان، ويُغلق لشركةٍ أو يُسعَّر لها من لوحتها. ويُسقط FeatureCatalogTest
 * كلّ ميزةٍ جديدة تُفتح للجميع.
 */
enum Feature: string
{
    case OrderReading = 'order_reading';
    case QuickEntry = 'quick_entry';
    case ExcelImport = 'excel_import';
    case Waybills = 'waybills';
    case Conversations = 'conversations';
    case Announcements = 'announcements';
    case AppAds = 'app_ads';

    /** في النظام قبل قائمة الميزات: مفتوحةٌ لكل شركةٍ ما لم تُغلق لها */
    private const INCLUDED = [
        self::QuickEntry, self::ExcelImport, self::Waybills, self::Conversations, self::Announcements, self::AppAds,
    ];

    public function label(): string
    {
        return match ($this) {
            self::OrderReading  => 'قراءة الطلب من صورة أو رسالة',
            self::QuickEntry    => 'الإدخال السريع',
            self::ExcelImport   => 'رفع الشحنات من ملف Excel',
            self::Waybills      => 'الوصولات المطبوعة مسبقاً',
            self::Conversations => 'المحادثات مع التجّار',
            self::Announcements => 'الإشعارات الجماعية',
            self::AppAds        => 'إعلانات التطبيق',
        };
    }

    /** ما تفعله، بكلام صاحب الشركة */
    public function description(): string
    {
        return match ($this) {
            self::OrderReading  => 'يرفع الموظّف أو التاجر لقطة شاشةٍ لمحادثة الزبون أو يلصق رسالته، فتمتلئ الشحنة: الاسم والهاتف والعنوان والسعر.',
            self::QuickEntry    => 'إدخال حتى ٣٠ شحنةً في جدولٍ واحد من لوحة المفاتيح.',
            self::ExcelImport   => 'رفع الشحنات من ملف Excel، من النظام ومن بوّابة التاجر.',
            self::Waybills      => 'دفاتر وصولاتٍ بأرقامٍ مطبوعة يكتب عليها التاجر بيده، وتُدخَل الشحنة بمسح الوصل.',
            self::Conversations => 'محادثات الدعم بين الشركة وتجّارها من البوّابة، بالملفات والصور.',
            self::Announcements => 'إشعارٌ واحدٌ يصل كلَّ التجّار أو كلَّ المناديب.',
            self::AppAds        => 'صورٌ إعلانية في بوّابة التاجر وتطبيق المندوب.',
        };
    }

    /** مفتوحةٌ في شركةٍ لم يُقرَّر لها فيها شيء */
    public function included(): bool
    {
        return in_array($this, self::INCLUDED, true);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::cases(), 'value');
    }
}
