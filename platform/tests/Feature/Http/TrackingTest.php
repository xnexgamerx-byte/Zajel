<?php

namespace Tests\Feature\Http;

use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\UpdateShipment;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تتبّع الزبون بلا حساب: رابط QR ببصمة، أو رقم الوصل مع آخر أربعة من الهاتف.
 *
 * أرقام الوصولات متسلسلة؛ فكل ما يجيب برقم الوصل وحده يفتح زبائن الشركة
 * كلّهم لمن يعدّ. هنا يُحرَس ألّا يكفي الرقم، وألّا يُفرَّق في الجواب بين
 * «غير موجود» و«أرقام خاطئة»، وألّا يظهر ما هو داخليّ.
 */
class TrackingTest extends TestCase
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
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
    }

    private function host(?Company $company = null): string
    {
        return 'http://'.($company ?? $this->company)->slug.'.'.config('zajel.tenant_domain');
    }

    private function makeShipment(?Company $company = null, array $overrides = []): Shipment
    {
        $company ??= $this->company;
        $merchant = $company->is($this->company) ? $this->merchant : $this->makeMerchant($company);
        $owner = $company->is($this->company) ? $this->owner : $this->makeUser($company);

        return Tenancy::runFor($company, fn () => app(CreateShipment::class)->handle(array_merge([
            'merchant_id'     => $merchant->id,
            'recipient_name'  => 'علي حسين جاسم',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد - الكرادة - زقاق ٧',
            'landmark'        => 'مقابل جامع الشيخ معروف',
            'cod_amount'      => 50_000,
        ], $overrides), $owner));
    }

    private function link(Shipment $shipment): string
    {
        return $this->host().'/t/'.$shipment->number.'/'.Tracking::token($shipment);
    }

    public function test_the_qr_link_shows_the_shipment_without_login(): void
    {
        $shipment = $this->makeShipment();

        $this->get($this->link($shipment))
            ->assertOk()
            ->assertSee($shipment->number)
            ->assertSee('وصلنا طلب شحنتك')
            ->assertSee('علي ح.')
            ->assertSee('50,000')
            // ما لا يخصّ غير صاحبه: الاسم كاملاً، والهاتف، والعنوان
            ->assertDontSee('علي حسين جاسم')
            ->assertDontSee('07801234567')
            ->assertDontSee('زقاق ٧');
    }

    public function test_a_guessed_or_borrowed_token_opens_nothing(): void
    {
        $mine = $this->makeShipment();
        $other = $this->makeShipment(overrides: ['recipient_phone' => '07709998887']);

        $this->get($this->host().'/t/'.$mine->number.'/0000000000000000')->assertNotFound();

        // بصمة شحنةٍ على رقم شحنةٍ أخرى
        $this->get($this->host().'/t/'.$other->number.'/'.Tracking::token($mine))->assertNotFound();
    }

    public function test_the_number_and_last_four_digits_lead_to_the_shipment(): void
    {
        $shipment = $this->makeShipment(overrides: ['recipient_phone_alt' => '07905556677']);

        $this->get($this->host().'/track?number='.$shipment->number.'&phone=4567')
            ->assertRedirect($this->link($shipment));

        // الهاتف البديل يكفي أيضاً، وبالأرقام العربية كما يكتبها الزبون
        $this->get($this->host().'/track?number='.$shipment->number.'&phone=٦٦٧٧')
            ->assertRedirect($this->link($shipment));
    }

    public function test_a_wrong_phone_and_an_unknown_number_get_the_same_answer(): void
    {
        $shipment = $this->makeShipment();

        $wrong = $this->from($this->host().'/track')
            ->get($this->host().'/track?number='.$shipment->number.'&phone=1111');
        $unknown = $this->from($this->host().'/track')
            ->get($this->host().'/track?number=999999&phone=4567');

        $wrong->assertRedirect($this->host().'/track')->assertSessionHasErrors('number');
        $unknown->assertRedirect($this->host().'/track')->assertSessionHasErrors('number');

        $message = fn ($response) => $response->baseResponse->getSession()->get('errors')->first('number');
        $this->assertSame($message($wrong), $message($unknown));
    }

    public function test_one_company_cannot_track_another_companys_shipments(): void
    {
        $theirs = $this->makeShipment($this->makeCompany('barq', 'البرق'));

        // الرابط الصحيح نفسه، على نطاق شركةٍ أخرى
        $this->get($this->host().'/t/'.$theirs->number.'/'.Tracking::token($theirs))->assertNotFound();
        $this->get($this->host().'/track?number='.$theirs->number.'&phone=4567')->assertSessionHasErrors('number');
    }

    public function test_internal_events_stay_internal(): void
    {
        $shipment = $this->makeShipment();

        Tenancy::runFor($this->company, fn () => app(UpdateShipment::class)->handle($shipment, [
            ...$shipment->only(['recipient_name', 'recipient_phone', 'governorate_id', 'city_id', 'address',
                'landmark', 'pieces_count', 'weight_grams', 'cod_amount', 'fees_paid_by', 'extra_fee', 'discount']),
            'notes' => 'ملاحظة داخلية للمندوب',
        ], $this->owner));

        $this->get($this->link($shipment))
            ->assertOk()
            ->assertSee('تم الإنشاء')
            ->assertDontSee('تعديل البيانات')
            ->assertDontSee('ملاحظة داخلية للمندوب');
    }

    public function test_lookups_are_rate_limited(): void
    {
        $shipment = $this->makeShipment();

        for ($i = 0; $i < 30; $i++) {
            $this->get($this->host().'/track?number='.$shipment->number.'&phone='.sprintf('%04d', $i));
        }

        $this->get($this->host().'/track?number='.$shipment->number.'&phone=4567')->assertStatus(429);

        // ورابط QR لا يُحسب عليه تخمين غيره: خلف عنوانٍ واحدٍ زبائن كثيرون
        $this->get($this->link($shipment))->assertOk();
    }
}
