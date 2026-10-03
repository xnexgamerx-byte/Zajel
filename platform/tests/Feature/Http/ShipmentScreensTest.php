<?php

namespace Tests\Feature\Http;

use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الشاشتان عبر HTTP كاملاً: الوسيط، المصادقة، التحقّق، العرض.
 * ما يمرّ هنا يمرّ في المتصفّح.
 */
class ShipmentScreensTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->user = $this->makeUser($this->company);
    }

    /** يحاكي النطاق الفرعي للشركة: zajel.zajel.iq */
    private function host(Company $company): string
    {
        return 'http://'.$company->slug.'.'.config('zajel.tenant_domain');
    }

    private function actingInCompany(?Company $company = null): static
    {
        $company ??= $this->company;

        return $this->actingAs(
            $company->is($this->company) ? $this->user : $this->makeUser($company)
        );
    }

    public function test_the_list_screen_renders_with_shipments(): void
    {
        $shipment = $this->makeShipment();

        $response = $this->actingInCompany()->get($this->host($this->company).'/shipments');

        $response->assertOk()
            ->assertSee($shipment->number)
            ->assertSee('علي حسين')
            ->assertSee('الشحنات');
    }

    public function test_the_list_screen_finds_a_shipment_by_receipt_number(): void
    {
        $wanted = $this->makeShipment(['recipient_name' => 'المطلوب']);
        $other  = $this->makeShipment(['recipient_name' => 'غير المطلوب', 'recipient_phone' => '07709998887']);

        $response = $this->actingInCompany()
            ->get($this->host($this->company).'/shipments?q='.$wanted->number);

        $response->assertOk()
            ->assertSee('المطلوب')
            ->assertDontSee('غير المطلوب');
    }

    public function test_an_unknown_subdomain_is_rejected(): void
    {
        $this->get('http://nope.'.config('zajel.tenant_domain').'/shipments')
            ->assertNotFound();
    }

    public function test_a_suspended_company_cannot_be_reached(): void
    {
        Tenancy::runAsPlatform(fn () => $this->company->update(['status' => 'suspended']));

        $this->actingInCompany()
            ->get($this->host($this->company).'/shipments')
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get($this->host($this->company).'/shipments')
            ->assertRedirect($this->host($this->company).'/login');
    }

    public function test_one_company_cannot_open_another_companys_shipment(): void
    {
        $foreign = $this->makeShipment();

        $other = $this->makeCompany('barq', 'البرق');

        $this->actingInCompany($other)
            ->get($this->host($other).'/shipments/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_a_session_from_another_company_is_ended(): void
    {
        $other = $this->makeCompany('barq', 'البرق');

        // مستخدم الزاجل يحاول فتح نظام البرق بجلسته
        $this->actingAs($this->user)
            ->get($this->host($other).'/shipments')
            ->assertRedirect($this->host($other).'/login');

        $this->assertGuest();
    }

    public function test_the_create_screen_renders_the_form(): void
    {
        $this->actingInCompany()
            ->get($this->host($this->company).'/shipments/create')
            ->assertOk()
            ->assertSee('شحنة جديدة')
            ->assertSee('أقرب نقطة دالّة')
            ->assertSee($this->merchant->business_name);
    }

    /** المبلغ يبدأ فارغاً بنصٍّ شفاف لا بصفرٍ يُمسح قبل الكتابة — والصفر يُكتب قصداً */
    public function test_the_amount_starts_empty_with_a_hint_and_is_still_required(): void
    {
        $html = $this->actingInCompany()->get($this->host($this->company).'/shipments/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input id="cod_amount"[^>]*placeholder="مثلاً 5 000"[^>]*value=""/u', $html);
        foreach (['extra_fee', 'discount', 'weight_grams'] as $field) {
            $this->assertMatchesRegularExpression('/<input id="'.$field.'"[^>]*value=""/u', $html, $field);
        }

        $this->actingInCompany()
            ->post($this->host($this->company).'/shipments', $this->validPayload(['cod_amount' => '']))
            ->assertSessionHasErrors(['cod_amount' => 'اكتب المبلغ المطلوب من الزبون — 0 إن كان مدفوعاً مسبقاً.']);

        // والرسوم والخصم والوزن الفارغة صفرٌ عند الحفظ
        $this->actingInCompany()
            ->post($this->host($this->company).'/shipments', $this->validPayload(['extra_fee' => '', 'discount' => '', 'weight_grams' => '']))
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () {
            $shipment = Shipment::latest('id')->firstOrFail();
            $this->assertSame(0, (int) $shipment->discount);
            $this->assertSame(0, (int) $shipment->weight_grams);
        });
    }

    public function test_creating_a_shipment_without_an_area_is_rejected(): void
    {
        $this->actingInCompany()
            ->post($this->host($this->company).'/shipments', $this->validPayload(['city_id' => '']))
            ->assertSessionHasErrors(['city_id' => 'اختر المنطقة.']);

        Tenancy::runFor($this->company, fn () => $this->assertSame(0, Shipment::count()));
    }

    /** الهاتف والمحافظة والمنطقة والمبلغ تكفي: الاسم والنقطة الدالّة لا يُلزَم بهما، وحرفٌ واحد يكفي */
    public function test_a_shipment_needs_only_phone_governorate_area_and_amount(): void
    {
        $this->actingInCompany()
            ->post($this->host($this->company).'/shipments', $this->validPayload(['recipient_name' => '', 'landmark' => '']))
            ->assertSessionHasNoErrors();

        $this->actingInCompany()
            ->post($this->host($this->company).'/shipments', $this->validPayload(['landmark' => 'ج']))
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () {
            [$bare, $short] = Shipment::orderBy('id')->get()->all();

            $this->assertSame(Shipment::UNNAMED_RECIPIENT, $bare->recipient_name);
            $this->assertSame('', $bare->landmark);
            $this->assertSame('', $bare->address);
            $this->assertSame($this->area(), $bare->city_id);
            $this->assertSame('ج', $short->landmark);
        });
    }

    public function test_creating_a_shipment_with_a_malformed_phone_is_rejected(): void
    {
        $this->actingInCompany()
            ->post($this->host($this->company).'/shipments', $this->validPayload(['recipient_phone' => '12345']))
            ->assertSessionHasErrors('recipient_phone');
    }

    public function test_a_city_from_a_different_governorate_is_rejected(): void
    {
        $basraCity = \App\Models\City::whereRelation('governorate', 'code', 'BSR')->firstOrFail();

        $this->actingInCompany()
            ->post($this->host($this->company).'/shipments', $this->validPayload([
                'governorate_id' => $this->baghdad()->id,
                'city_id'        => $basraCity->id,
            ]))
            ->assertSessionHasErrors('city_id');
    }

    public function test_a_merchant_of_another_company_is_rejected(): void
    {
        $other = $this->makeCompany('barq', 'البرق');
        $foreignMerchant = $this->makeMerchant($other, 'M9001');

        $this->actingInCompany()
            ->post($this->host($this->company).'/shipments', $this->validPayload([
                'merchant_id' => $foreignMerchant->id,
            ]))
            ->assertSessionHasErrors('merchant_id');
    }

    /**
     * النموذج كما تُكتب ورقة الطلب: الاسم أوّلاً، ثم الهاتف، ثم المحافظة والمنطقة والنقطة
     * الدالّة، ثم المبلغ والعدد والملاحظة، و«حفظ الشحنة» تحتها — بلا «من يدفع الأجرة»
     * وبلا حساب الأجور والعمولات.
     */
    public function test_the_form_reads_like_an_order_slip_without_fees(): void
    {
        $html = $this->actingInCompany()->get($this->host($this->company).'/shipments/create')->assertOk()->getContent();

        $at = fn (string $needle) => mb_strpos($html, $needle);
        $order = ['id="recipient_name"', 'id="recipient_phone"', 'id="governorate_id"', 'id="city_id"',
                  'id="landmark"', 'id="cod_amount"', 'id="pieces_count"', 'id="notes"', 'حفظ الشحنة'];

        foreach ($order as $needle) {
            $this->assertNotFalse($at($needle), $needle);
        }
        $this->assertSame($order, collect($order)->sortBy($at)->values()->all());

        // الاسم أوّل ما يُكتب
        $this->assertMatchesRegularExpression('/<input id="recipient_name"[^>]*autofocus/u', $html);

        foreach (['من يدفع الأجرة', 'fees_paid_by', 'عمولة التحصيل', 'مستحقّ التاجر', 'مجموع الأجور'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
    }

    public function test_without_who_pays_the_merchant_pays_and_the_next_form_keeps_the_merchant(): void
    {
        $payload = $this->validPayload();
        unset($payload['fees_paid_by']);

        $this->actingInCompany()->post($this->host($this->company).'/shipments', $payload)->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () {
            $shipment = Shipment::latest('id')->firstOrFail();
            $this->assertSame('merchant', $shipment->fees_paid_by);
            $this->assertSame(45_000, (int) $shipment->merchant_due);
        });

        // النموذج التالي: التاجر نفسه مختار، والحقول فارغة
        $html = $this->actingInCompany()->get($this->host($this->company).'/shipments/create')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="'.$this->merchant->id.'"\s+selected/u', $html);
        $this->assertMatchesRegularExpression('/<input id="recipient_phone"[^>]*value=""/u', $html);
    }

    public function test_a_valid_shipment_is_created_priced_and_shown(): void
    {
        $response = $this->actingInCompany()
            ->post($this->host($this->company).'/shipments', $this->validPayload());

        $shipment = Tenancy::runFor($this->company, fn () => Shipment::firstOrFail());

        // الطلبات تُدخَل متتابعة: يعود النموذج فارغاً للتالية، وبطاقة المحفوظة فوقه
        $response->assertRedirect($this->host($this->company).'/shipments/create')
            ->assertSessionHas('created', $shipment->id);

        $this->actingInCompany()
            ->get($this->host($this->company).'/shipments/create')
            ->assertOk()
            ->assertSee('حُفظت الشحنة')
            ->assertSee('000001')
            ->assertSee('اطبع الوصل');

        $this->assertSame('000001', $shipment->number);
        $this->assertSame(ShipmentStatus::Created, $shipment->status);
        $this->assertSame(5000, $shipment->delivery_fee);
        $this->assertSame(45_000, $shipment->merchant_due);   // 50,000 − 5,000
        $this->assertSame($this->user->id, $shipment->created_by_user_id);

        $this->actingInCompany()
            ->get($this->host($this->company).'/shipments/'.$shipment->id)
            ->assertOk()
            ->assertSee('000001')
            ->assertSee('مقابل جامع الشيخ معروف')
            ->assertSee('سجلّ الشحنة');
    }

    public function test_the_quote_endpoint_prices_before_saving(): void
    {
        $this->actingInCompany()
            ->postJson($this->host($this->company).'/quote', [
                'merchant_id'    => $this->merchant->id,
                'governorate_id' => $this->baghdad()->id,
                'weight_grams'   => 1000,
                'cod_amount'     => 50_000,
                'fees_paid_by'   => 'merchant',
            ])
            ->assertOk()
            ->assertJson([
                'delivery_fee' => 5000,
                'total_fees'   => 5000,
                'merchant_due' => 45_000,
                'matched'      => true,
            ]);
    }

    public function test_the_quote_endpoint_refuses_a_merchant_of_another_company(): void
    {
        $other = $this->makeCompany('barq', 'البرق');
        $foreignMerchant = $this->makeMerchant($other, 'M9001');

        $this->actingInCompany()
            ->postJson($this->host($this->company).'/quote', [
                'merchant_id'    => $foreignMerchant->id,
                'governorate_id' => $this->baghdad()->id,
            ])
            ->assertNotFound();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'merchant_id'     => $this->merchant->id,
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'city_id'         => $this->area(),
            'landmark'        => 'مقابل جامع الشيخ معروف',
            'pieces_count'    => 1,
            'weight_grams'    => 1000,
            'cod_amount'      => 50_000,
            'fees_paid_by'    => 'merchant',
        ], $overrides);
    }

    private function makeShipment(array $overrides = []): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle(array_merge([
            'merchant_id'     => $this->merchant->id,
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد - الكرادة',
            'landmark'        => 'مقابل جامع الشيخ معروف',
            'cod_amount'      => 50_000,
        ], $overrides), $this->user));
    }
}
