<?php

namespace Tests\Feature\Shipments;

use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\Company;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Orders\OrderReader;
use App\Services\Orders\SpokenOrder;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * الطلب بصوت التاجر (docs/plan/40): ما سمعه المتصفّح نصّاً متّصلاً، وأرقامه كلمات، يُعاد
 * أسطراً وأرقاماً فيملأ نموذج الشحنة كأيّ رسالة.
 */
class SpokenOrderTest extends TestCase
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
        $this->setFeature($this->company, Feature::OrderReading);
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function hear(string $speech): array
    {
        return Tenancy::runFor($this->company, fn () => app(OrderReader::class)->fromSpeech($speech));
    }

    private function governorate(string $code): int
    {
        return (int) Governorate::where('code', $code)->value('id');
    }

    private function city(string $name, string $code): int
    {
        return (int) City::where('governorate_id', $this->governorate($code))->where('name_ar', $name)->value('id');
    }

    public function test_a_whole_order_said_in_one_breath_fills_the_form(): void
    {
        $reading = $this->hear('الاسم علي حسين الرقم صفر سبعة سبعة صفر واحد اثنين ثلاثة اربعة خمسة ستة سبعة '
            .'بغداد الكرادة قرب ساحة كهرمانة المبلغ خمسة وعشرين الف');

        $this->assertSame([
            'recipient_phone' => '07701234567',
            'cod_amount'      => 25_000,
            'governorate_id'  => $this->governorate('BGD'),
            'city_id'         => $this->city('الكرادة', 'BGD'),
            'landmark'        => 'قرب ساحة كهرمانة',
            'recipient_name'  => 'علي حسين',
        ], $reading['fields']);
        $this->assertSame([], $reading['missing']);
    }

    public function test_without_the_field_words_the_order_of_speech_is_enough(): void
    {
        // كما يكتبها مايك لوحة المفاتيح: الأرقام أرقام، والكلام سطرٌ واحد
        $reading = $this->hear('زينب كاظم 0780 123 4567 البصرة الجزائر قرب مستشفى الموانئ 35 الف');

        $this->assertSame('زينب كاظم', $reading['fields']['recipient_name']);
        $this->assertSame('07801234567', $reading['fields']['recipient_phone']);
        $this->assertSame($this->city('الجزائر', 'BSR'), $reading['fields']['city_id']);
        $this->assertSame('قرب مستشفى الموانئ', $reading['fields']['landmark']);
        $this->assertSame(35_000, $reading['fields']['cod_amount']);
    }

    public function test_iraqi_words_a_second_number_pieces_and_a_note(): void
    {
        $reading = $this->hear('اسمها فاطمة جاسم، رقمها صفر سبعة ثمانية صفر دبل سبعة تسعة اربعة ثلاثة ثنين واحد، '
            .'الرقم الثاني 07712345678، الحلة الجمعية، العدد ثلاث قطع، سعرها مية وخمسين الف ونص، ملاحظة اتصل قبل لا توصل');

        $this->assertSame('فاطمة جاسم', $reading['fields']['recipient_name']);
        $this->assertSame('07807794321', $reading['fields']['recipient_phone']);
        $this->assertSame('07712345678', $reading['fields']['recipient_phone_alt']);
        $this->assertSame($this->city('الجمعية', 'BBL'), $reading['fields']['city_id']);
        $this->assertSame(3, $reading['fields']['pieces_count']);
        $this->assertSame(150_500, $reading['fields']['cod_amount']);
        $this->assertSame('اتصل قبل لا توصل', $reading['fields']['notes']);
    }

    /** @return array<string, array{string, string}> */
    public static function spokenNumbers(): array
    {
        return [
            'آحاد الهاتف'          => ['الرقم صفر سبعة خمسة صفر واحد اثنين ثلاثة اربعة خمسة ستة سبعة', '07501234567'],
            'دبل وتربل'            => ['صفر سبعة سبعة صفر دبل واحد تربل ثلاثة اربعة خمسة', '07701133345'],
            'خمسة وعشرين الف'      => ['المبلغ خمسة وعشرين الف', 'المبلغ 25 الف'],
            'و منفصلة'             => ['المبلغ خمسة و ثلاثين الف', 'المبلغ 35 الف'],
            'الف ونص'              => ['المبلغ خمسة وعشرين الف ونص', 'المبلغ 25.5 الف'],
            'مية وخمسين'           => ['السعر مية وخمسين الف', 'المبلغ 150 الف'],
            'ثلاث مية'             => ['السعر ثلاث مية الف', 'المبلغ 300 الف'],
            'الفين'                => ['المبلغ الفين', 'المبلغ 2 الف'],
            'مليون ونص'            => ['الحساب مليون ونص', 'المبلغ 1500 الف'],
            'خمسطعش بالعراقي'      => ['سعرها خمسطعش', 'المبلغ 15'],
            'بلا الف'              => ['المبلغ خمسة وعشرين', 'المبلغ 25'],
            'رقمٌ مكتوب يبقى'      => ['المبلغ 25 الف', 'المبلغ 25 الف'],
            'ست زينب اسمٌ لا رقم'  => ['ست زينب', 'ست زينب'],
            'سبع قطع'              => ['سبع قطع', '7 قطع'],
            'هاتفٌ بمقاطع'          => ['صفر سبعه سبعه اربعمية و عشرة سبعه سبعه ثلاث تساعات', '07741077999'],
            'سبعمية وسبعين'        => ['صفر سبعمية وسبعين مية وعشرين ثلاثة اربعة خمسة ستة', '07701203456'],
            'اربع اصفار'           => ['صفر سبعة سبعة صفر واحد اربع اصفار ثمانية ثمانية', '07701000088'],
            'مبلغٌ في آخر الكلام'  => ['بغداد الكرادة خمسة وعشرين', "بغداد الكرادة\n25"],
        ];
    }

    #[DataProvider('spokenNumbers')]
    public function test_numbers_are_heard_as_they_are_said(string $speech, string $expected): void
    {
        $this->assertSame($expected, SpokenOrder::normalise($speech));
    }

    public function test_fields_are_told_apart_without_their_words(): void
    {
        // كما قاله التاجر: الاسم ثم الرقم بمقاطع ثم العنوان ثم المبلغ — بلا «الاسم» ولا «الرقم» ولا «المبلغ»
        $reading = $this->hear('مصطفى عادل صفر سبعه سبعه اربعمية و عشرة سبعه سبعه ثلاث تساعات بغداد الكرادة قرب الجامع خمسة وعشرين الف');

        $this->assertSame('مصطفى عادل', $reading['fields']['recipient_name']);
        $this->assertSame('07741077999', $reading['fields']['recipient_phone']);
        $this->assertSame($this->city('الكرادة', 'BGD'), $reading['fields']['city_id']);
        $this->assertSame('قرب الجامع', $reading['fields']['landmark']);
        $this->assertSame(25_000, $reading['fields']['cod_amount']);
        $this->assertSame([], $reading['missing']);
    }

    public function test_a_name_outside_the_first_names_list_and_glued_to_the_address(): void
    {
        // اسمٌ ليس في قائمة الأسماء الأولى، والعنوان بعده في النفَس نفسه
        $reading = $this->hear('رفل عبد الله البصرة الجزائر صفر سبعة ثمانية صفر واحد اثنين ثلاثة اربعة خمسة ستة سبعة السعر ثلاثين');

        $this->assertSame('رفل عبد الله', $reading['fields']['recipient_name']);
        $this->assertSame($this->city('الجزائر', 'BSR'), $reading['fields']['city_id']);
        $this->assertSame('07801234567', $reading['fields']['recipient_phone']);
        $this->assertSame(30_000, $reading['fields']['cod_amount']);
    }

    public function test_the_merchant_speaks_in_the_portal_and_nothing_is_saved(): void
    {
        $user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));

        // الزرّ في نموذج الشحنة، والمايك مسموحٌ للنظام نفسه
        $this->actingAs($user)->get($this->host().'/portal/shipments/create')->assertOk()
            ->assertSee('data-order-talk', false)
            ->assertSee('تكلّم')
            ->assertHeader('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=(self), payment=(), usb=()');

        $this->actingAs($user)->postJson($this->host().'/portal/shipments/read', [
            'text' => 'الاسم حسن كاظم رقمه صفر سبعة ثمانية واحد اثنين ثلاثة اربعة خمسة ستة سبعة ثمانية النجف السعر ثلاثين الف',
            'spoken' => '1',
        ])->assertOk()
            ->assertJsonPath('fields.recipient_name', 'حسن كاظم')
            ->assertJsonPath('fields.recipient_phone', '07812345678')
            ->assertJsonPath('fields.governorate_id', $this->governorate('NJF'))
            ->assertJsonPath('fields.cod_amount', 30_000);

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => \App\Models\Shipment::count()));
    }

    public function test_staff_dictate_too_and_a_pasted_message_is_read_as_before(): void
    {
        $this->actingAs($this->owner)->postJson($this->host().'/shipments/read', [
            'text' => 'علي حسين صفر سبعة سبعة صفر واحد اثنين ثلاثة اربعة خمسة ستة سبعة بغداد الكرادة خمسة وعشرين الف',
            'spoken' => true,
        ])->assertOk()
            ->assertJsonPath('fields.recipient_name', 'علي حسين')
            ->assertJsonPath('fields.recipient_phone', '07701234567')
            ->assertJsonPath('fields.cod_amount', 25_000);

        // بلا «spoken» تُقرأ الرسالة الملصوقة بقواعدها هي
        $this->actingAs($this->owner)->postJson($this->host().'/shipments/read', ['text' => "علي حسين\n07712345678\nبغداد الكرادة\n25 الف"])
            ->assertOk()->assertJsonPath('fields.cod_amount', 25_000)->assertJsonPath('missing', []);
    }
}
