<?php

namespace Tests\Feature\Portal;

use App\Actions\Waybills\IssueWaybillBook;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WaybillBook;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نموذج «شحنة جديدة» في بوابة التاجر (docs/plan/28): حقول طلبه وحدها، كما في
 * النظام المعتاد — الاسم والرقم والعنوان والسعر والعدد أساسها، ومعها الهاتف الثانوي
 * ونوع البضاعة وحجم الطلب ونوعه والملاحظات و«البروموكود»: رقم الوصل المطبوع.
 */
class MerchantShipmentFormTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $alpha;

    private Merchant $beta;

    private User $staff;

    private User $alphaUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->alpha = $this->makeMerchant($this->company, 'M0001');
        $this->beta = $this->makeMerchant($this->company, 'M0002');
        $this->staff = $this->makeUser($this->company);

        $this->alphaUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر ألفا', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->alpha->id, 'is_active' => true,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'recipient_name'  => 'طه محمد',
            'recipient_phone' => '07712345678',
            'governorate_id'  => $this->baghdad()->id,
            'city_id'         => $this->area('المنصور'),
            'pieces_count'    => 1,
            'cod_amount'      => 25_000,
        ], $overrides);
    }

    private function submit(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->alphaUser)->post($this->host().'/portal/shipments', $this->payload($overrides));
    }

    private function book(?Merchant $merchant, int $size = 3): WaybillBook
    {
        return Tenancy::runFor($this->company, fn () => app(IssueWaybillBook::class)->handle($merchant, $size, $this->staff));
    }

    private function only(): Shipment
    {
        return Tenancy::runFor($this->company, fn () => Shipment::sole());
    }

    // ------------------------------------------------------------ النموذج

    public function test_the_form_holds_only_the_order_fields(): void
    {
        $page = $this->actingAs($this->alphaUser)->get($this->host().'/portal/shipments/create')->assertOk();

        // أساسه أوّلاً كما يُكتب الطلب، و«حفظ الشحنة» تحته
        $page->assertSeeInOrder([
            'name="recipient_name"', 'name="recipient_phone"', 'name="recipient_phone_alt"',
            'name="governorate_id"', 'name="city_id"', 'name="landmark"',
            'name="cod_amount"', 'name="pieces_count"', 'name="description"',
            'name="size"', 'name="type"', 'name="notes"', 'name="waybill"', 'حفظ الشحنة',
        ], false);

        $page->assertSee('السعر مع التوصيل')->assertSee('نوع البضاعة')->assertSee('حجم الطلب')
            ->assertSee('نوع الطلب')->assertSee('طلب جديد')->assertSee('استبدال')
            ->assertSee('رقم الوصل المطبوع');

        // العدد يُكتب ١ تلقائياً، والمؤشّر في اسم الزبون
        $this->assertMatchesRegularExpression('/name="pieces_count"[^>]*value="1"/', $page->getContent());
        $this->assertMatchesRegularExpression('/name="recipient_name"[^>]*autofocus/', $page->getContent());

        foreach (['fees_paid_by', 'merchant_reference', 'weight_grams', 'is_fragile', 'allow_open', 'fee_prepaid'] as $field) {
            $page->assertDontSee('name="'.$field.'"', false);
        }

        $page->assertDontSee('من يدفع');
    }

    public function test_the_merchant_cannot_set_what_is_not_in_the_form(): void
    {
        // تاجرٌ يُحاسَب مقدّماً لا يُسقط ذلك عن نفسه بإرسال fee_prepaid=0
        Tenancy::runFor($this->company, fn () => $this->alpha->update(['prepaid_billing' => true]));

        $this->submit([
            'fees_paid_by' => 'customer', 'fee_prepaid' => '0', 'weight_grams' => 5000,
            'merchant_reference' => 'X-1', 'is_fragile' => '1', 'allow_open' => '1',
        ])->assertSessionHasNoErrors();

        $shipment = $this->only();

        $this->assertSame('merchant', $shipment->fees_paid_by);
        $this->assertTrue($shipment->fee_prepaid);
        $this->assertSame(0, $shipment->weight_grams);
        $this->assertNull($shipment->merchant_reference);
        $this->assertFalse($shipment->is_fragile);
        $this->assertFalse($shipment->allow_open);
    }

    public function test_the_price_count_and_extras_are_saved(): void
    {
        $this->submit([
            'recipient_phone_alt' => '07809876543', 'landmark' => 'قرب الجامع', 'pieces_count' => 3,
            'description' => 'ملابس', 'size' => 'large', 'type' => 'exchange', 'notes' => 'اتصل قبل الوصول',
        ])->assertSessionHasNoErrors();

        $shipment = $this->only();

        $this->assertSame(25_000, (int) $shipment->cod_amount);
        $this->assertSame(3, $shipment->pieces_count);
        $this->assertSame('07809876543', $shipment->recipient_phone_alt);
        $this->assertSame('ملابس', $shipment->description);
        $this->assertSame('large', $shipment->size);
        $this->assertSame('exchange', $shipment->type);
        $this->assertSame('merchant_portal', $shipment->source);
    }

    public function test_a_plain_order_is_new_and_of_normal_size(): void
    {
        $this->submit()->assertSessionHasNoErrors();

        $this->assertSame('delivery', $this->only()->type);
        $this->assertSame('normal', $this->only()->size);
    }

    public function test_unknown_size_or_type_is_refused(): void
    {
        $this->submit(['size' => 'huge', 'type' => 'return'])->assertSessionHasErrors(['size', 'type']);
    }

    public function test_after_saving_the_form_opens_for_the_next_order(): void
    {
        $this->submit()->assertRedirect($this->host().'/portal/shipments/create');

        $shipment = $this->only();

        $this->actingAs($this->alphaUser)
            ->withSession(['created' => $shipment->id])
            ->get($this->host().'/portal/shipments/create')
            ->assertOk()
            ->assertSee('حُفظت الشحنة')
            ->assertSee($shipment->number)
            ->assertSee('طه محمد')
            ->assertSee('اطبع الوصل')
            ->assertSee('اطلب استلاماً');
    }

    public function test_the_saved_banner_never_shows_another_merchants_shipment(): void
    {
        $foreign = Tenancy::runFor($this->company, fn () => app(\App\Actions\Shipments\CreateShipment::class)->handle(
            $this->payload(['merchant_id' => $this->beta->id, 'recipient_name' => 'زبون بيتا']), $this->staff));

        $this->actingAs($this->alphaUser)
            ->withSession(['created' => $foreign->id])
            ->get($this->host().'/portal/shipments/create')
            ->assertOk()
            ->assertDontSee('حُفظت الشحنة')
            ->assertDontSee('زبون بيتا');
    }

    // ------------------------------------------------- الاستبدال والحجم يُريان

    public function test_exchange_and_large_size_show_on_the_label_and_to_staff(): void
    {
        $this->submit(['size' => 'large', 'type' => 'exchange'])->assertSessionHasNoErrors();
        $shipment = $this->only();

        $this->actingAs($this->alphaUser)
            ->get($this->host().'/portal/shipments/labels?ids[]='.$shipment->id)
            ->assertOk()->assertSee('استبدال')->assertSee('حجم كبير');

        $this->actingAs($this->alphaUser)
            ->get($this->host().'/portal/shipments/'.$shipment->id)
            ->assertOk()->assertSee('استبدال · كبير');

        $this->actingAs($this->staff)
            ->get($this->host().'/shipments/'.$shipment->id)
            ->assertOk()->assertSeeInOrder(['نوع الطلب', 'استبدال', 'حجم الطلب', 'كبير']);
    }

    public function test_a_plain_label_carries_no_exchange_or_size_tag(): void
    {
        $this->submit()->assertSessionHasNoErrors();

        $this->actingAs($this->alphaUser)
            ->get($this->host().'/portal/shipments/labels?ids[]='.$this->only()->id)
            ->assertOk()->assertDontSee('<span class="tag">استبدال</span>', false)->assertDontSee('حجم كبير');
    }

    // ------------------------------------------------ رقم الوصل المطبوع («البروموكود»)

    public function test_the_merchants_printed_waybill_carries_the_shipment(): void
    {
        $book = $this->book($this->alpha);
        // كما يكتبه هاتفٌ عربي: أرقامٌ عربية وفراغات
        $typed = strtr(substr($book->firstCode(), 0, 4).' '.substr($book->firstCode(), 4),
            array_combine(range(0, 9), ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩']));

        $this->submit(['waybill' => $typed])->assertSessionHasNoErrors()->assertSessionHas('created');

        $shipment = $this->only();

        $this->assertSame($book->firstCode(), $shipment->barcode);
        $this->assertSame($book->id, $shipment->waybill_book_id);
        $this->assertSame('merchant_portal', $shipment->source);
        $this->assertNotSame($book->firstCode(), $shipment->number);

        // لا يحتاج وصلاً آخر: الوصل المطبوع على الطرد
        $this->actingAs($this->alphaUser)
            ->withSession(['created' => $shipment->id])
            ->get($this->host().'/portal/shipments/create')
            ->assertSee('على الوصل المطبوع')
            ->assertDontSee('اطبع الوصل');

        // ومسحه في الشركة يجد الشحنة
        $this->actingAs($this->staff)
            ->get($this->host().'/shipments?q='.$book->firstCode())
            ->assertOk()->assertSee($shipment->number);
    }

    public function test_a_warehouse_waybill_handed_to_the_merchant_is_accepted(): void
    {
        $book = $this->book(null);

        $this->submit(['waybill' => $book->firstCode()])->assertSessionHasNoErrors();

        $this->assertSame($book->firstCode(), $this->only()->barcode);
    }

    public function test_another_merchants_waybill_is_refused_without_naming_them(): void
    {
        $book = $this->book($this->beta);

        $response = $this->submit(['waybill' => $book->firstCode()])
            ->assertSessionHasErrors(['waybill' => 'الرقم '.$book->firstCode().' ليس من وصولاتك المطبوعة.']);

        $this->assertStringNotContainsString($this->beta->business_name, json_encode(session('errors')->getMessages(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => Shipment::count()));
        $response->assertRedirect();
    }

    public function test_a_used_waybill_is_refused(): void
    {
        $book = $this->book($this->alpha);
        $this->submit(['waybill' => $book->firstCode()])->assertSessionHasNoErrors();
        $first = $this->only();

        $this->submit(['waybill' => $book->firstCode(), 'recipient_phone' => '07711111111'])
            ->assertSessionHasErrors(['waybill' => "استعملت هذا الوصل للشحنة {$first->number}."]);

        // وصل مخزنٍ استعمله تاجرٌ آخر: لا يُذكر رقم شحنته
        $warehouse = $this->book(null);
        Tenancy::runFor($this->company, fn () => app(\App\Actions\Waybills\CreateFromWaybill::class)->handle(
            $warehouse->firstCode(), $this->payload(['merchant_id' => $this->beta->id]), $this->staff));

        $this->submit(['waybill' => $warehouse->firstCode(), 'recipient_phone' => '07722222222'])
            ->assertSessionHasErrors(['waybill' => 'استُعمل هذا الوصل لشحنةٍ أخرى.']);
    }

    public function test_a_number_that_is_not_a_printed_waybill_is_refused(): void
    {
        $this->submit(['waybill' => '12345'])
            ->assertSessionHasErrors(['waybill' => 'الرقم 12345 ليس من وصولاتك المطبوعة.']);

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => Shipment::count()));
    }
}
