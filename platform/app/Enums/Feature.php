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
    case Accounting = 'accounting';
    case ScanReceipts = 'scan_receipts';
    case ScanParcels = 'scan_parcels';
    case MerchantStaff = 'merchant_staff';
    case NotifyTexts = 'notify_texts';

    /**
     * في النظام قبل قائمة الميزات: مفتوحةٌ لكل شركةٍ ما لم تُغلق لها. والمحاسبة (docs/plan/68)
     * كانت تعمل في كل شركةٍ قبل أن يكون لها مفتاح، فتبقى كذلك بقرار صاحب المنصّة.
     */
    private const INCLUDED = [
        self::QuickEntry, self::ExcelImport, self::Waybills, self::Conversations, self::Announcements, self::AppAds,
        self::Accounting,
    ];

    public function label(): string
    {
        return match ($this) {
            self::OrderReading  => 'قراءة الطلب بالذكاء الاصطناعي',
            self::QuickEntry    => 'الإدخال السريع',
            self::ExcelImport   => 'رفع الشحنات من ملف Excel',
            self::Waybills      => 'الوصولات المطبوعة مسبقاً',
            self::Conversations => 'المحادثات مع التجّار',
            self::Announcements => 'الإشعارات الجماعية',
            self::AppAds        => 'إعلانات التطبيق',
            self::Accounting    => 'المحاسبة والأرباح: لوحة الأرباح ودفتر القيود وإقفال الفترات',
            self::ScanReceipts  => 'استلام الحساب والراجع بمسح الورقة',
            self::ScanParcels   => 'مسح الشحنة عند الاستلام والتسليم',
            self::MerchantStaff => 'موظّفو التاجر',
            self::NotifyTexts   => 'نصوص الإشعارات',
        };
    }

    /** ما تفعله، بكلام صاحب الشركة */
    public function description(): string
    {
        return match ($this) {
            self::OrderReading  => 'لقطة شاشةٍ لمحادثة الزبون، أو رسالته ملصوقة، أو كلام التاجر بصوته: يقرؤها الذكاء الاصطناعي فتمتلئ الشحنة — الاسم والهاتف والعنوان والسعر.',
            self::QuickEntry    => 'إدخال حتى ٣٠ شحنةً في جدولٍ واحد من لوحة المفاتيح.',
            self::ExcelImport   => 'رفع الشحنات من ملف Excel، من النظام ومن بوّابة التاجر.',
            self::Waybills      => 'دفاتر وصولاتٍ بأرقامٍ مطبوعة يكتب عليها التاجر بيده، وتُدخَل الشحنة بمسح الوصل.',
            self::Conversations => 'محادثات الدعم بين الشركة وتجّارها من البوّابة، بالملفات والصور.',
            self::Announcements => 'إشعارٌ واحدٌ يصل كلَّ التجّار أو كلَّ المناديب.',
            self::AppAds        => 'صورٌ إعلانية في بوّابة التاجر وتطبيق المندوب.',
            self::Accounting    => 'لوحة الأرباح ووجبات التحصيل وسحب الأرباح والإعدادات المالية، وميزان المراجعة ودفتر القيود، وإقفال الفترات، '
                .'والأرصدة الافتتاحية، والموقف النقدي وتوقّعه، والتنبيهات المالية. إغلاقها يُخفي شاشاتها كلّها من الشركة ويوقف فحص التنبيهات، '
                .'والقيود تبقى تُكتب: فتحها ثانيةً يعرض أرقاماً كاملة.',
            self::ScanReceipts  => 'التاجر لا يؤكّد استلام دفعة حسابه أو رواجعه إلّا بمسح الباركود المطبوع على الورقة التي وصلته معها، أو بكتابة رمزها. '
                .'فلا يُؤكَّد ما لم يصل، ولا تُطبع الورقة من بوابته برمزها.',
            self::ScanParcels   => 'مندوب الاستلام لا يستلم طرود التاجر إلّا بمسح باركود كلّ شحنةٍ أو كتابة رقمها، فيُستلم ما مُسح وحده. '
                .'ومندوب التوصيل لا يسجّل شيئاً على شحنةٍ عند الباب إلّا بعد مسحها أو كتابة رقمها: الطرد في يده لا في ذاكرته.',
            self::MerchantStaff => 'يضيف التاجر من تطبيقه موظّفين لمتجره، لكلٍّ دخوله وصلاحياته: إدخال، أو محاسب، أو متابعة، أو ما يختاره. '
                .'والموظّف لا يرى ولا يعمل إلّا ما فُتح له.',
            self::NotifyTexts   => 'الشركة تكتب بنفسها ما يصل هواتف تجّارها ومناديبها وموظّفي الكول سنتر: عنوان كلّ إشعارٍ ونصّه، '
                .'بخاناتٍ تُملأ من الشحنة. ومن يعدّلها صلاحيةٌ لمدير الشركة يمنحها من يشاء.',
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
