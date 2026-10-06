<?php

namespace Tests\Feature\Shipments;

use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\Company;
use App\Models\Governorate;
use App\Models\GovernorateSetting;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Orders\OrderReader;
use App\Services\Orders\ScreenshotText;
use App\Services\Orders\UnreadableImage;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «اقرأ الطلب من صورة أو رسالة» (docs/plan/34): رسالة زبونٍ من واتساب أو ماسنجر — نصّاً
 * ملصوقاً أو لقطة شاشة تُقرأ على الخادم نفسه — تملأ نموذج الشحنة، ولا يُحفظ شيء.
 *
 * لقطتا tests/Fixtures/orders محادثتان مصطنعتان ببياناتٍ وهمية.
 */
class OrderReadingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        // ميزةٌ تُفتح لكل شركةٍ من المنصّة، ومطفأةٌ حتى تُفتح (docs/plan/35)
        $this->setFeature($this->company, Feature::OrderReading);
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    /** @return array{fields: array<string, string|int>, found: array<string, string>, missing: list<string>, lines: list<string>, warnings: list<string>} */
    private function read(string $text, ?Company $company = null): array
    {
        return Tenancy::runFor($company ?? $this->company, fn () => app(OrderReader::class)->fromText($text));
    }

    private function governorate(string $code): int
    {
        return (int) Governorate::where('code', $code)->value('id');
    }

    private function city(string $name, string $code): int
    {
        return (int) City::where('governorate_id', $this->governorate($code))->where('name_ar', $name)->value('id');
    }

    private function fixture(string $name): string
    {
        return base_path("tests/Fixtures/orders/{$name}");
    }

    /** قراءة الصور الحقيقية حيث Tesseract بالعربية مثبّت (الخادم والفحص الآلي) */
    private function needsTesseract(): void
    {
        if (! app(ScreenshotText::class)->available()) {
            $this->markTestSkipped('Tesseract بالعربية غير مثبّت هنا: apt install tesseract-ocr tesseract-ocr-ara');
        }
    }

    // ------------------------------------------------------------ قراءة الرسالة

    public function test_a_chat_in_separate_messages_fills_the_order(): void
    {
        $reading = $this->read(implode("\n", [
            'السلام عليكم', '10:12 م',
            'اريد اطلب الجاكيت الاسود', '10:15 م',
            'الاسم: علي حسين',
            'رقمي 07712345678',
            'بغداد الكرادة قرب ساحة كهرمانة',
            'تمام السعر 25 الف مع التوصيل', '12:30 م',
            'اوكي',
        ]));

        $this->assertSame([
            'recipient_phone' => '07712345678',
            'cod_amount'      => 25_000,
            'governorate_id'  => $this->governorate('BGD'),
            'city_id'         => $this->city('الكرادة', 'BGD'),
            'landmark'        => 'قرب ساحة كهرمانة',
            'recipient_name'  => 'علي حسين',
        ], $reading['fields']);
        $this->assertSame([], $reading['missing']);
        $this->assertSame('25,000 د.ع', $reading['found']['cod_amount']);
        $this->assertSame('بغداد', $reading['found']['governorate_id']);

        // أوقات الرسائل لا تبقى أسطراً
        $this->assertNotContains('10:12 م', $reading['lines']);
        $this->assertNotContains('اوكي', array_values($reading['fields']));
    }

    public function test_arabic_digits_a_name_beside_the_phone_and_a_dashed_address(): void
    {
        $reading = $this->read("مرحبا\nزينب كاظم\n٠٧٨٠١٢٣٤٥٦٧\nالبصرة - الجزائر - قرب مستشفى الموانئ\nالسعر ٣٥ الف");

        $this->assertSame('زينب كاظم', $reading['fields']['recipient_name']);
        $this->assertSame('07801234567', $reading['fields']['recipient_phone']);
        $this->assertSame(35_000, $reading['fields']['cod_amount']);

        // «الجزائر» في بغداد والبصرة والقادسية: المذكورة محافظتها
        $this->assertSame($this->governorate('BSR'), $reading['fields']['governorate_id']);
        $this->assertSame($this->city('الجزائر', 'BSR'), $reading['fields']['city_id']);
        $this->assertSame('قرب مستشفى الموانئ', $reading['fields']['landmark']);
    }

    public function test_labelled_fields_and_a_spelling_of_the_area(): void
    {
        $reading = $this->read(implode("\n", [
            'طلب جديد', 'الزبون: حيدر عباس', 'الموبايل: 0750 123 4567', 'المحافظة: اربيل',
            'المنطقة: عنكاوة', 'قرب كنيسة مار يوسف', 'المبلغ: 40,000',
        ]));

        $this->assertSame([
            'recipient_phone' => '07501234567',
            'cod_amount'      => 40_000,
            'governorate_id'  => $this->governorate('ERB'),
            'city_id'         => $this->city('عنكاوا', 'ERB'),
            'landmark'        => 'قرب كنيسة مار يوسف',
            'recipient_name'  => 'حيدر عباس',
        ], $reading['fields']);
    }

    public function test_a_city_names_its_governorate_and_one_line_holds_everything(): void
    {
        $reading = $this->read("الموصل حي الزهور قرب جامع الزهور 07801112233 ، 30 الف ، عدد 2\nملاحظة: اتصل قبل الوصول");

        $this->assertSame([
            'recipient_phone' => '07801112233',
            'cod_amount'      => 30_000,
            'pieces_count'    => 2,
            'governorate_id'  => $this->governorate('NIN'),
            'city_id'         => $this->city('حي الزهور', 'NIN'),
            // «الزهور» منطقةٌ مرّةً ونقطةٌ دالّةٌ مرّة
            'landmark'        => 'قرب جامع الزهور',
            'notes'           => 'اتصل قبل الوصول',
        ], $reading['fields']);
    }

    public function test_an_international_number_and_dinars(): void
    {
        $reading = $this->read("+964 770 123 4567\nالحلة الجمعية\n15000 دينار");

        $this->assertSame('07701234567', $reading['fields']['recipient_phone']);
        $this->assertSame($this->governorate('BBL'), $reading['fields']['governorate_id']);
        $this->assertSame($this->city('الجمعية', 'BBL'), $reading['fields']['city_id']);
        $this->assertSame(15_000, $reading['fields']['cod_amount']);
    }

    public function test_the_area_alone_names_its_governorate(): void
    {
        $reading = $this->read("07712345678\nالكرادة قرب ساحة كهرمانة\n25 الف");

        $this->assertSame($this->governorate('BGD'), $reading['fields']['governorate_id']);
        $this->assertSame($this->city('الكرادة', 'BGD'), $reading['fields']['city_id']);
        $this->assertSame([], $reading['warnings']);

        // وأرقام البيت عنوانٌ كامل: لا تُحذف من النقطة الدالّة
        $this->assertSame('محلة 905 زقاق 12 دار 4',
            $this->read("بغداد الكرادة محلة 905 زقاق 12 دار 4\n07712345678\n25 الف")['fields']['landmark']);
    }

    public function test_an_area_in_several_governorates_is_not_guessed(): void
    {
        $reading = $this->read("07712345678\nالجزائر قرب المستشفى\n25 الف");

        $this->assertArrayNotHasKey('governorate_id', $reading['fields']);
        $this->assertArrayNotHasKey('city_id', $reading['fields']);
        $this->assertSame(['المحافظة', 'المنطقة'], $reading['missing']);
        $this->assertSame(['«الجزائر» منطقةٌ في أكثر من محافظة: اختر المحافظة ثم المنطقة.'], $reading['warnings']);
        $this->assertSame('قرب المستشفى', $reading['fields']['landmark']);
    }

    public function test_a_part_of_a_compound_area_name_finds_it(): void
    {
        // «مدينة الصدر - جوادر» يُكتب «جوادر»، و«مدينة الصدر - قطاع 39» يُكتب «الصدر قطاع 39»
        $this->assertSame($this->city('مدينة الصدر - جوادر', 'BGD'), $this->read("بغداد جوادر\n07712345678")['fields']['city_id']);
        $sector = $this->read("الصدر قطاع 39 دار 12\n07712345678")['fields'];
        $this->assertSame($this->city('مدينة الصدر - قطاع 39', 'BGD'), $sector['city_id']);
        $this->assertSame('دار 12', $sector['landmark']);

        // والاسم الكامل يُقدَّم على جزء اسمٍ آخر: «الطوبجي» لا «حي السلام / الطوبجي»
        $this->assertSame($this->city('الطوبجي', 'BGD'), $this->read("بغداد الطوبجي\n07712345678")['fields']['city_id']);

        // واسم المنطقة يبدأ باسم محافظتها: «دهوك مالطا» منطقةٌ كاملة لا نقطةٌ دالّة اسمها «مالطا»
        $duhok = $this->read("دهوك مالطا\n07501112233")['fields'];
        $this->assertSame($this->city('دهوك مالطا', 'DHK'), $duhok['city_id']);
        $this->assertArrayNotHasKey('landmark', $duhok);

        // وجزءٌ في أكثر من منطقة: المحافظة تُملأ، والمنطقة يختارها من يُدخل الطلب
        $reading = $this->read("بغداد حي السلام قرب الجامع\n07712345678");
        $this->assertSame($this->governorate('BGD'), $reading['fields']['governorate_id']);
        $this->assertArrayNotHasKey('city_id', $reading['fields']);
        $this->assertSame(['«حي السلام» يطابق أكثر من منطقة في بغداد: اختر المنطقة.'], $reading['warnings']);
        $this->assertSame('قرب الجامع', $reading['fields']['landmark']);
    }

    public function test_a_misspelt_area_or_governorate_is_still_found(): void
    {
        // نقطةٌ زائدة أو ناقصة: «الجمزة» هي «الحمزة»، و«تجف» هي «نجف»
        $reading = $this->read("بابل الجمزة الغربي\n07712345678");
        $this->assertSame($this->city('الحمزة الغربي جنوب بابل', 'BBL'), $reading['fields']['city_id']);

        $reading = $this->read("تجف حي الحسين شارع السوق العصري\n07712345678");
        $this->assertSame($this->governorate('NJF'), $reading['fields']['governorate_id']);
        $this->assertSame('شارع السوق العصري', $reading['fields']['landmark']);

        // وجهةٌ مخالفة ليست خطأً إملائياً: «الحمزة الشرقي» لا «الحمزة الغربي» ولا «حمزة دلي»
        $reading = $this->read("بابل الحمزة الشرقي\n07712345678");
        $this->assertArrayNotHasKey('city_id', $reading['fields']);
        $this->assertSame('الحمزة الشرقي', $reading['fields']['landmark']);
    }

    public function test_the_rest_of_the_order_message_becomes_the_note(): void
    {
        // رسالة الطلب كما يرسلها الزبون، بأرقامٍ عربية: ما ليس اسماً ولا رقماً ولا عنواناً ولا سعراً ملاحظة
        $reading = $this->read("حسن كريم\n٠٧٨٠١٢٣٤٥٦٧\nبابل الحمزة الغربي\nمنطقة العوادل\nمنقلة\n٣٠٠٠٠٠\nتوصيل مستعجل");

        $this->assertSame([
            'recipient_phone' => '07801234567',
            'cod_amount'      => 300_000,
            'governorate_id'  => $this->governorate('BBL'),
            'city_id'         => $this->city('الحمزة الغربي جنوب بابل', 'BBL'),
            'landmark'        => 'العوادل',
            'recipient_name'  => 'حسن كريم',
            'notes'           => 'منقلة، توصيل مستعجل',
        ], $reading['fields']);

        // وكلام المحادثة ليس ملاحظة، ولا ما في رسالةٍ أخرى
        $notes = $this->read("حسن كريم\n07712345678\nبغداد الكرادة\nشوكت توصلون\nجاكيت اسود\n\nتمام\nكرتونة كبيرة")['fields']['notes'];
        $this->assertSame('جاكيت اسود', $notes);
    }

    #[DataProvider('prices')]
    public function test_the_amount_as_it_is_written_in_iraq(string $text, ?int $amount): void
    {
        $this->assertSame($amount, $this->read($text)['fields']['cod_amount'] ?? null);
    }

    public static function prices(): array
    {
        return [
            'بالألف'                   => ['25 الف', 25_000],
            'بالهمزة'                  => ['السعر ١٥ ألف', 15_000],
            'بالآلاف بفاصلة'            => ['المبلغ: 40,000', 40_000],
            'بالآلاف بمسافة'            => ['السعر 40 000', 40_000],
            'كلمة السعر بلا ألف'        => ['السعر 25', 25_000],
            'نصف ألف'                  => ['السعر 22.5 الف', 22_500],
            'كسرٌ بمنزلتين لا وقت'       => ['السعر 22.50 الف', 22_500],
            'ووقت الرسالة ليس مبلغاً'     => ["السعر 25 الف\n10:42 م", 25_000],
            'دينار'                    => ['15000 دينار', 15_000],
            'د.ع'                      => ['25,000 د.ع', 25_000],
            'رقمٌ وحده في سطره'          => ["07712345678\n25", 25_000],
            'والمذكور بكلمته يُقدَّم'      => ["السعر 30 الف\n25", 30_000],
            'سنةٌ ليست مبلغاً'           => ['2026', null],
            'ولا رقمٌ بصفرٍ في أوّله'      => ["07712345678\n012", null],
            'k'                        => ['35k', 35_000],
            'المجموع يُقدَّم'            => ["السعر 25 الف\nالمجموع 30 الف مع التوصيل\nتمام", 30_000],
            'الأخير يصحّح ما قبله'       => ["السعر 20 الف\nلا خليها 22 الف", 22_000],
            'الهاتف ليس مبلغاً'          => ['رقمي 07712345678', null],
            'رقمٌ بلا ما يدلّ على المبلغ' => ['عندي 3 اطفال', null],
            'أصغر من مبلغ'              => ['السعر 0.1', null],
        ];
    }

    public function test_a_second_number_beside_the_first_is_the_alternative_phone(): void
    {
        $reading = $this->read("علي حسين\n07712345678\n07801234567\nبغداد الكرادة\n25 الف");

        $this->assertSame('07712345678', $reading['fields']['recipient_phone']);
        $this->assertSame('07801234567', $reading['fields']['recipient_phone_alt']);
        $this->assertSame('علي حسين', $reading['fields']['recipient_name']);
        $this->assertSame([], $reading['warnings']);
    }

    public function test_a_distant_second_number_is_only_mentioned(): void
    {
        $reading = $this->read("07712345678\nبغداد الكرادة\n25 الف\nواذا ما رديت\nاتصل على اخوي\n07801234567");

        $this->assertSame('07712345678', $reading['fields']['recipient_phone']);
        $this->assertArrayNotHasKey('recipient_phone_alt', $reading['fields']);
        $this->assertSame(['في الرسالة أكثر من رقم: أُخذ الأوّل 07712345678.'], $reading['warnings']);
    }

    public function test_greetings_and_addresses_are_not_names(): void
    {
        $reading = $this->read("السلام عليكم\n07712345678\nبغداد الكرادة");

        $this->assertArrayNotHasKey('recipient_name', $reading['fields']);
        $this->assertArrayNotHasKey('recipient_name', $this->read("07712345678\nشكرا جزيلا")['fields']);
        $this->assertArrayNotHasKey('recipient_name', $this->read("صباح الخير\n07712345678")['fields']);
    }

    public function test_a_line_beside_the_phone_is_a_name_only_when_it_starts_with_one(): void
    {
        // «شوكت تجون نطوني خبر» جملةٌ من المحادثة لا اسم؛ و«ام علي» و«عبد الله كريم» أسماء
        $this->assertArrayNotHasKey('recipient_name', $this->read("نجف حي الحسين\n07712345678\nشوكت تجون نطوني خبر")['fields']);
        $this->assertSame('ام علي', $this->read("ام علي\n07712345678")['fields']['recipient_name']);
        $this->assertSame('عبد الله كريم', $this->read("عبد الله كريم\n07712345678")['fields']['recipient_name']);

        // ورأس المحادثة اسمٌ في الصورة وحدها: النصّ الملصوق أوّله رسالة
        $this->assertArrayNotHasKey('recipient_name', $this->read("الزهراء قدوتي\nنجف حي الحسين\nرقمي هو\n07712345678")['fields']);
    }

    public function test_messages_copied_together_from_whatsapp(): void
    {
        // نسخ عدّة رسائل معاً: وقت كلّ رسالةٍ ومُرسلها قبلها، والرسالة بعدّة أسطر تكملتها بلا بادئة
        $reading = $this->read(implode("\n", [
            '[10:12 م، 2026/10/5] علي حسين: السلام عليكم',
            '[10:13 م، 2026/10/5] علي حسين: رقمي 07712345678',
            'بغداد الكرادة',
            '[10:15 م، 2026/10/5] متجر الورد: تمام السعر 25 الف',
        ]));

        $this->assertSame('07712345678', $reading['fields']['recipient_phone']);
        $this->assertSame($this->city('الكرادة', 'BGD'), $reading['fields']['city_id']);
        $this->assertSame(25_000, $reading['fields']['cod_amount']);
        // اسم المُرسل كما حفظه التاجر
        $this->assertSame('علي حسين', $reading['fields']['recipient_name']);
        $this->assertNotContains('[10:12 م، 2026/10/5] علي حسين: السلام عليكم', $reading['lines']);

        // ومُرسلٌ غير محفوظ يظهر برقمه: هو رقم الزبون إن لم يُكتب غيره
        $unsaved = $this->read("10/5/26, 10:12 PM - +964 780 123 4567: بغداد الكرادة\n10/5/26, 10:13 PM - +964 780 123 4567: 25 الف");
        $this->assertSame('07801234567', $unsaved['fields']['recipient_phone']);
        $this->assertSame(['الرقم 07801234567 رقم مُرسل الرسالة في واتساب: تأكّد أنّه رقم الاستلام.'], $unsaved['warnings']);
    }

    public function test_pieces_and_notes(): void
    {
        $this->assertSame(3, $this->read('3 قطع')['fields']['pieces_count']);
        $this->assertSame(2, $this->read('العدد: 2')['fields']['pieces_count']);
        $this->assertSame(2, $this->read('اريد قطعتين')['fields']['pieces_count']);
        $this->assertSame('الطابق الثاني', $this->read('ملاحظات: الطابق الثاني')['fields']['notes']);
    }

    public function test_a_message_without_an_order_says_what_is_missing(): void
    {
        // و«السلام» منطقةٌ في محافظاتٍ كثيرة، لا في التحيّة
        $reading = $this->read("السلام عليكم\nصباح النور\nشكراً");

        $this->assertSame([], $reading['fields']);
        $this->assertSame(['رقم الهاتف', 'المحافظة', 'المنطقة', 'المبلغ'], $reading['missing']);
    }

    public function test_only_the_company_s_own_list_of_areas_and_governorates(): void
    {
        $barq = $this->makeCompany('barq', 'البرق');
        $own = Tenancy::runFor($this->company, fn () => City::create([
            'governorate_id' => $this->governorate('BSR'), 'name_ar' => 'حي الأمير الجديد',
            'company_id' => $this->company->id, 'is_active' => true,
        ]));

        // منطقةٌ أضافتها الشركة لنفسها: تُقرأ لها لا لغيرها
        $this->assertSame($own->id, $this->read("البصرة حي الأمير الجديد\n07712345678")['fields']['city_id']);
        $this->assertNotSame($own->id, $this->read("البصرة حي الأمير الجديد\n07712345678", $barq)['fields']['city_id'] ?? null);

        // ومحافظةٌ أوقفتها الشركة لا تُملأ، ولا منطقةٌ منها
        Tenancy::runFor($this->company, fn () => GovernorateSetting::create([
            'governorate_id' => $this->governorate('ERB'), 'is_active' => false,
        ]));
        $reading = $this->read("اربيل عنكاوة\n07712345678");
        $this->assertArrayNotHasKey('governorate_id', $reading['fields']);
        $this->assertArrayNotHasKey('city_id', $reading['fields']);
    }

    // ------------------------------------------------------------ الصورة

    public function test_a_light_chat_screenshot_is_read(): void
    {
        $this->needsTesseract();

        $reading = Tenancy::runFor($this->company, fn () => app(OrderReader::class)->fromImage($this->fixture('chat-light.png')));

        $this->assertSame([
            'recipient_phone' => '07712345678',
            'cod_amount'      => 25_000,
            'governorate_id'  => $this->governorate('BGD'),
            'city_id'         => $this->city('الكرادة', 'BGD'),
            'landmark'        => 'قرب ساحة كهرمانة',
            'recipient_name'  => 'علي حسين',
        ], $reading['fields']);
    }

    public function test_an_instagram_chat_with_arabic_digits_and_a_typo_is_read(): void
    {
        $this->needsTesseract();

        // وضعٌ داكن وفقاعةٌ بنفسجية متدرّجة بكتابةٍ بيضاء، والرقم والسعر بأرقامٍ عربية، و«الجمزة» خطأٌ في «الحمزة»
        $reading = Tenancy::runFor($this->company, fn () => app(OrderReader::class)->fromImage($this->fixture('instagram-dark.png')));

        $this->assertSame('حسن كريم', $reading['fields']['recipient_name']);
        $this->assertSame('07801234567', $reading['fields']['recipient_phone']);
        $this->assertSame(300_000, $reading['fields']['cod_amount']);
        $this->assertSame($this->governorate('BBL'), $reading['fields']['governorate_id']);
        $this->assertSame($this->city('الحمزة الغربي جنوب بابل', 'BBL'), $reading['fields']['city_id']);
        $this->assertSame('العوادل', $reading['fields']['landmark']);
        $this->assertStringEndsWith('توصيل مستعجل', $reading['fields']['notes']);
        $this->assertSame([], $reading['missing']);
    }

    public function test_a_messenger_inbox_screenshot_is_read(): void
    {
        $this->needsTesseract();

        // رأسٌ بصورةٍ واسمٍ وأزرار، وإعلانٌ بسعرٍ بأرقامٍ عربية، والرقم وحده في فقاعته
        $reading = Tenancy::runFor($this->company, fn () => app(OrderReader::class)->fromImage($this->fixture('messenger-desktop.png')));

        $this->assertSame([
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->governorate('BSR'),
            'city_id'         => $this->city('العشار', 'BSR'),
            'landmark'        => 'شارع المطاعم',
            'recipient_name'  => 'نور الهدى حسن',
        ], $reading['fields']);
        $this->assertSame(['في الصورة سعرٌ لم تُقرأ أرقامه: اكتبه.'], $reading['warnings']);
    }

    public function test_a_dark_screenshot_is_read(): void
    {
        $this->needsTesseract();

        $reading = Tenancy::runFor($this->company, fn () => app(OrderReader::class)->fromImage($this->fixture('order-dark.png')));

        $this->assertSame('07501234567', $reading['fields']['recipient_phone']);
        $this->assertSame('حيدر عباس', $reading['fields']['recipient_name']);
        $this->assertSame($this->city('عنكاوا', 'ERB'), $reading['fields']['city_id']);
        $this->assertSame(40_000, $reading['fields']['cod_amount']);
        $this->assertSame([], $reading['missing']);
    }

    public function test_a_framed_or_transparent_screenshot_is_read(): void
    {
        $this->needsTesseract();

        $chat = imagecreatefrompng($this->fixture('chat-light.png'));
        imagepalettetotruecolor($chat);
        [$w, $h] = [imagesx($chat), imagesy($chat)];
        $dir = storage_path('framework/testing/orders');
        File::ensureDirectoryExists($dir);

        // داخل إطارٍ أسود كحوافّ الهاتف: كل صفٍّ فيه حبر
        $framed = imagecreatetruecolor($w + 120, $h + 120);
        imagefill($framed, 0, 0, imagecolorallocate($framed, 0, 0, 0));
        imagecopy($framed, $chat, 60, 60, 0, 0, $w, $h);
        imagepng($framed, "{$dir}/framed.png");

        // وبحاشيةٍ شفّافة لونها الخفيّ أسود
        $clear = imagecreatetruecolor($w + 200, $h + 200);
        imagealphablending($clear, false);
        imagesavealpha($clear, true);
        imagefill($clear, 0, 0, imagecolorallocatealpha($clear, 0, 0, 0, 127));
        imagecopy($clear, $chat, 100, 100, 0, 0, $w, $h);
        imagepng($clear, "{$dir}/clear.png");

        try {
            foreach (['framed', 'clear'] as $name) {
                $fields = Tenancy::runFor($this->company, fn () => app(OrderReader::class)->fromImage("{$dir}/{$name}.png"))['fields'];

                $this->assertSame('07712345678', $fields['recipient_phone'] ?? null, $name);
                $this->assertSame($this->city('الكرادة', 'BGD'), $fields['city_id'] ?? null, $name);
                $this->assertSame(25_000, $fields['cod_amount'] ?? null, $name);
            }
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_a_file_that_is_not_an_image_is_refused_before_reading(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'order');
        file_put_contents($file, 'ليست صورة');

        try {
            $this->expectExceptionObject(new UnreadableImage('الملف ليس صورة.'));
            app(ScreenshotText::class)->read($file);
        } finally {
            @unlink($file);
        }
    }

    public function test_a_huge_image_is_refused_before_it_is_opened(): void
    {
        // رأس PNG وحده بأبعاد ٥٠٠٠×٥٠٠٠: لا تُفتح الصورة لتُعرف أبعادها
        $header = pack('N', 5000).pack('N', 5000)."\x08\x02\x00\x00\x00";
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$header.pack('N', crc32('IHDR'.$header));
        $file = tempnam(sys_get_temp_dir(), 'order');
        file_put_contents($file, $png);

        try {
            $this->expectExceptionObject(new UnreadableImage('الصورة كبيرة جداً: أرسل لقطة الشاشة نفسها.'));
            app(ScreenshotText::class)->read($file);
        } finally {
            @unlink($file);
        }
    }

    // ------------------------------------------------------------ الشاشة والمسار

    public function test_staff_read_a_pasted_message_into_the_shipment_form(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/shipments/create')->assertOk()
            ->assertSee('data-order-reader', false)
            ->assertSee($this->host().'/shipments/read', false)
            ->assertSee('data-form="shipment-form"', false);

        $this->actingAs($this->owner)
            ->postJson($this->host().'/shipments/read', ['text' => "علي حسين\n07712345678\nبغداد الكرادة\n25 الف"])
            ->assertOk()
            ->assertJsonPath('fields.recipient_phone', '07712345678')
            ->assertJsonPath('fields.city_id', $this->city('الكرادة', 'BGD'))
            ->assertJsonPath('fields.cod_amount', 25_000)
            ->assertJsonPath('found.governorate_id', 'بغداد')
            ->assertJsonPath('missing', []);
    }

    public function test_reading_needs_the_right_to_create_shipments(): void
    {
        $accountant = $this->makeUser($this->company, UserRole::Accountant);

        $this->actingAs($accountant)->postJson($this->host().'/shipments/read', ['text' => '07712345678'])->assertForbidden();
    }

    public function test_a_merchant_reads_an_order_in_the_portal(): void
    {
        $user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));

        $this->actingAs($user)->get($this->host().'/portal/shipments/create')->assertOk()
            ->assertSee($this->host().'/portal/shipments/read', false)
            ->assertSee('id="order-form"', false);

        $this->actingAs($user)
            ->postJson($this->host().'/portal/shipments/read', ['text' => "07712345678\nالحلة الجمعية\nالسعر 15"])
            ->assertOk()
            ->assertJsonPath('fields.governorate_id', $this->governorate('BBL'))
            ->assertJsonPath('fields.cod_amount', 15_000);

        // ولا يُحفظ شيء
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => \App\Models\Shipment::count()));
    }

    public function test_reading_has_its_own_limit_apart_from_printing_waybills(): void
    {
        $user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));

        // عشرون قراءةً في الدقيقة لا تُحتسب من دفاتر الوصولات العشرين في الساعة
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)->postJson($this->host().'/portal/shipments/read', ['text' => '07712345678'])->assertOk();
        }
        $this->actingAs($user)->postJson($this->host().'/portal/waybills', [])->assertUnprocessable();

        // وحدّ القراءة نفسه ثلاثون في الدقيقة
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->postJson($this->host().'/portal/shipments/read', ['text' => '07712345678'])->assertOk();
        }
        $this->actingAs($user)->postJson($this->host().'/portal/shipments/read', ['text' => '07712345678'])->assertTooManyRequests();
    }

    public function test_a_request_without_an_image_or_a_message_is_refused_in_arabic(): void
    {
        $this->actingAs($this->owner)->postJson($this->host().'/shipments/read', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.image.0', 'اختر صورةً أو الصق نص الرسالة.');

        $this->actingAs($this->owner)->postJson($this->host().'/shipments/read', [
            'image' => UploadedFile::fake()->create('order.pdf', 20, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonPath('errors.image.0', 'الصورة JPG أو PNG أو WEBP — لقطة شاشة الهاتف.');
    }

    public function test_a_screenshot_is_read_on_the_server_and_not_kept(): void
    {
        // البرنامج نفسه مُحاكى: ما يُختبر هنا الطريق من الرفع إلى الحقول
        Process::fake([
            '*--list-langs*'   => Process::result("List of available languages in \"/usr/share/tesseract-ocr/5/tessdata/\" (3):\nara\neng\nosd\n"),
            "*'--psm' '7'"    => Process::result("الاسم: علي حسين\fرقمي 07712345678\fبغداد الكرادة قرب ساحة كهرمانة\fالسعر 25 الف"),
            '*'               => Process::result(''),
        ]);

        $this->actingAs($this->owner)->post($this->host().'/shipments/read', [
            'image' => new UploadedFile($this->fixture('chat-light.png'), 'chat.png', 'image/png', null, true),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('fields.recipient_name', 'علي حسين')
            ->assertJsonPath('fields.recipient_phone', '07712345678')
            ->assertJsonPath('fields.landmark', 'قرب ساحة كهرمانة')
            ->assertJsonPath('fields.cod_amount', 25_000);

        Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), '-l ara'));

        // أسطر الصورة المقصوصة تُحذف مع الطلب
        $this->assertSame([], File::directories(storage_path('app/private/ocr')));
    }

    public function test_a_failed_reading_says_so_without_server_details(): void
    {
        Process::fake([
            '*--list-langs*' => Process::result("ara\neng\n"),
            '*'              => Process::result(errorOutput: 'Error opening data file /usr/share/tesseract-ocr/5/tessdata/ara.traineddata', exitCode: 1),
        ]);

        $this->actingAs($this->owner)->post($this->host().'/shipments/read', [
            'image' => new UploadedFile($this->fixture('order-dark.png'), 'order.png', 'image/png', null, true),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'تعذّرت قراءة الصورة.']);

        $this->assertSame([], File::directories(storage_path('app/private/ocr')));
    }

    public function test_without_the_reader_installed_the_form_offers_pasting_only(): void
    {
        config(['zajel.ocr.binary' => '/nonexistent/tesseract']);

        $this->actingAs($this->owner)->get($this->host().'/shipments/create')->assertOk()
            ->assertSee('اقرأ الطلب من رسالة')
            ->assertDontSee('data-order-image', false);

        $this->actingAs($this->owner)->post($this->host().'/shipments/read', [
            'image' => new UploadedFile($this->fixture('chat-light.png'), 'chat.png', 'image/png', null, true),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'قراءة الصور غير مثبّتة على هذا الخادم بعد — الصق نص الرسالة.');
    }
}
