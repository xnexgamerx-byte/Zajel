<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\NormaliseDigits;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * الأرقام كما تُكتب: «60 000» و«٦٠٬٠٠٠» تُقبل 60000، والهاتف بأيّ صيغةٍ عراقية 07 وتسعة.
 *
 * الواجهة ترسل الخانات وحدها (number-inputs.js)؛ وهذا ما يصل بغيرها فيُقبل كما يُحسب.
 */
class NumberInputTest extends TestCase
{
    use RefreshDatabase;

    private function normalised(array $input): array
    {
        $request = Request::create('/', 'POST', $input);

        return (new NormaliseDigits)->handle($request, fn (Request $r) => $r)->all();
    }

    public function test_grouped_and_arabic_digits_arrive_as_plain_numbers(): void
    {
        $out = $this->normalised([
            'cod_amount' => '60 000', 'amount' => '١٠٠٬٠٠٠', 'fee' => '1,250,000', 'counted' => '-5 000',
            'percent' => '٢٫٥', 'rows' => [['amount' => '25 000']],
        ]);

        $this->assertSame('60000', $out['cod_amount']);
        $this->assertSame('100000', $out['amount']);
        $this->assertSame('1250000', $out['fee']);
        $this->assertSame('-5000', $out['counted']);
        $this->assertSame('2.5', $out['percent']);
        $this->assertSame('25000', $out['rows'][0]['amount']);
    }

    public function test_phones_arrive_as_eleven_digits_from_any_iraqi_form(): void
    {
        $out = $this->normalised([
            'phone' => '+964 770 123 4567', 'recipient_phone' => '٠٧٧٠ ١٢٣ ٤٥٦٧', 'phone_alt' => '7801234567',
            'support_whatsapp' => '00964 790 111 2222', 'rows' => [['recipient_phone' => '0770-123-4567']],
        ]);

        $this->assertSame('07701234567', $out['phone']);
        $this->assertSame('07701234567', $out['recipient_phone']);
        $this->assertSame('07801234567', $out['phone_alt']);
        $this->assertSame('07901112222', $out['support_whatsapp']);
        $this->assertSame('07701234567', $out['rows'][0]['recipient_phone']);
    }

    /** ما ليس رقماً واحداً يبقى كما كُتب: وصولاتٌ ملصوقة، ونصٌّ فيه أرقام، وكلمة مرور */
    public function test_text_lists_and_passwords_are_left_alone(): void
    {
        $out = $this->normalised([
            'q' => '180089 180090', 'numbers' => "180089\n180090", 'landmark' => 'قرب مدرسة ٢٠',
            'password' => '1 234', 'phone' => '0770123',
        ]);

        $this->assertSame('180089 180090', $out['q']);
        $this->assertSame("180089\n180090", $out['numbers']);
        $this->assertSame('قرب مدرسة ٢٠', $out['landmark']);
        $this->assertSame('1 234', $out['password']);
        $this->assertSame('0770123', $out['phone']);     // ناقصٌ يبقى، فتقول القاعدة لماذا
    }

    public function test_a_shipment_typed_with_spaces_and_arabic_digits_is_saved_as_numbers(): void
    {
        $this->seedReference();
        $company = $this->makeCompany('zajel', 'الزاجل');
        $merchant = $this->makeMerchant($company, 'M0001');
        $owner = $this->makeUser($company);
        $host = 'http://zajel.'.config('zajel.tenant_domain');

        $this->actingAs($owner)->post($host.'/shipments', [
            'merchant_id' => $merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '٠٧٧٠ ١٢٣ ٤٥٦٧',
            'governorate_id' => $this->baghdad()->id,
            'city_id' => Tenancy::runFor($company, fn () => \App\Models\City::where('governorate_id', $this->baghdad()->id)->value('id')),
            'address' => 'الكرادة', 'landmark' => 'قرب الجامع', 'pieces_count' => '1',
            'cod_amount' => '60 000', 'fees_paid_by' => 'merchant',
        ])->assertSessionHasNoErrors();

        Tenancy::runFor($company, function () {
            $shipment = Shipment::latest('id')->firstOrFail();
            $this->assertSame(60000, (int) $shipment->cod_amount);
            $this->assertSame('07701234567', $shipment->recipient_phone);
        });
    }

    public function test_a_short_phone_is_refused_with_a_clear_message(): void
    {
        $this->seedReference();
        $company = $this->makeCompany('zajel', 'الزاجل');
        $this->makeMerchant($company, 'M0001');
        $owner = $this->makeUser($company);

        $this->actingAs($owner)->post('http://zajel.'.config('zajel.tenant_domain').'/merchants', [
            'business_name' => 'متجر', 'phone' => '0770123', 'settlement_cycle' => 'weekly',
            'payout_method' => 'cash', 'status' => 'active',
        ])->assertSessionHasErrors(['phone' => 'الهاتف يجب أن يبدأ بـ 07 ويتكوّن من 11 رقماً.']);
    }

    /** هاتف السائق كان نصّاً حرّاً: صار ١١ رقماً كسائر الهواتف، برسالة اللغة العامّة */
    public function test_a_driver_phone_is_eleven_digits_too(): void
    {
        $this->seedReference();
        $company = $this->makeCompany('zajel', 'الزاجل');
        $owner = $this->makeUser($company);

        $this->actingAs($owner)->post('http://zajel.'.config('zajel.tenant_domain').'/manifests', [
            'from_hub_id' => 1, 'to_hub_id' => 2, 'driver_phone' => '0770 12',
        ])->assertSessionHasErrors(['driver_phone' => 'رقم الهاتف يجب أن يبدأ بـ 07 ويتكوّن من 11 رقماً.']);
    }
}
