<?php

namespace Tests\Feature\Shipments;

use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Tenant\QuickEntryController;
use App\Models\City;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * الإدخال السريع: جدولٌ حتى ثلاثين صفّاً والمبلغ بالألف، يُحفظ كلّه أو لا يُحفظ.
 */
class QuickEntryTest extends TestCase
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

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function city(string $name, string $code = 'BGD'): int
    {
        return City::where('governorate_id', Governorate::where('code', $code)->value('id'))->where('name_ar', $name)->value('id');
    }

    private function row(array $overrides = []): array
    {
        return $overrides + [
            'amount' => '25', 'recipient_phone' => '07701234567', 'recipient_name' => '',
            'governorate_id' => $this->baghdad()->id, 'city_id' => $this->city('الكرادة'), 'landmark' => 'قرب ساحة كهرمانة',
            'merchant_reference' => '', 'notes' => '',
        ];
    }

    #[DataProvider('amounts')]
    public function test_amounts_are_typed_in_thousands(string $typed, ?int $dinars): void
    {
        $this->assertSame($dinars, QuickEntryController::thousands($typed));
    }

    public static function amounts(): array
    {
        return [
            'ألوف'            => ['25', 25_000],
            'نصف'             => ['25.5', 25_500],
            'فاصلة عربية'     => ['٢٥٫٥', 25_500],
            'أرقام عربية'     => ['١٥٠', 150_000],
            'مدفوع مسبقاً'    => ['0', 0],
            'فاصل آلاف عربي'  => ['١٬٥٠٠', 1_500_000],
            'فارغ'            => ['', null],
            'كُتب كاملاً'      => ['25000', null],
            'فاصلة مبهمة'     => ['1,500', null],
            'نصّ'             => ['خمسة', null],
        ];
    }

    public function test_a_merchant_table_creates_every_filled_row_and_skips_the_empty(): void
    {
        $karrada = $this->city('الكرادة');
        $basra = Governorate::where('code', 'BSR')->value('id');

        $this->actingAs($this->owner)
            ->post($this->host().'/shipments/quick', [
                'mode' => 'merchant', 'merchant_id' => $this->merchant->id,
                'rows' => [
                    $this->row(['city_id' => $karrada, 'merchant_reference' => 'R-1', 'exchange' => '1']),
                    ['governorate_id' => $this->baghdad()->id, 'amount' => '', 'recipient_phone' => '', 'landmark' => ''], // فارغ
                    $this->row(['amount' => '٤٠', 'recipient_phone' => '0780 111 2233', 'governorate_id' => $basra,
                                'city_id' => $this->city('العشار', 'BSR'), 'recipient_name' => 'حسين', 'landmark' => '']),
                ],
            ])
            ->assertRedirect($this->host().'/shipments/quick?mode=merchant')
            ->assertSessionHas('success')
            ->assertSessionHas('created_ids', fn ($ids) => count($ids) === 2)
            ->assertSessionHasInput('merchant_id', $this->merchant->id);

        Tenancy::runFor($this->company, function () use ($karrada, $basra) {
            [$first, $second] = Shipment::orderBy('id')->get()->all();

            $this->assertSame(25_000, (int) $first->cod_amount);
            $this->assertSame($karrada, $first->city_id);
            $this->assertSame('R-1', $first->merchant_reference);
            $this->assertSame('exchange', $first->type);
            $this->assertSame('الزبون', $first->recipient_name);
            $this->assertSame('قرب ساحة كهرمانة', $first->landmark);
            $this->assertSame('', $first->address);
            $this->assertSame(ShipmentStatus::Created, $first->status);

            $this->assertSame(40_000, (int) $second->cod_amount);
            $this->assertSame('07801112233', $second->recipient_phone);
            $this->assertSame($basra, $second->governorate_id);
            $this->assertSame('حسين', $second->recipient_name);
            $this->assertSame('', $second->landmark);
        });
    }

    /** المنطقة لا تُترك حيث للمحافظة مناطق؛ والنقطة الدالّة تُترك */
    public function test_a_row_without_an_area_is_pointed_at(): void
    {
        $this->actingAs($this->owner)
            ->from($this->host().'/shipments/quick')
            ->post($this->host().'/shipments/quick', [
                'mode' => 'merchant', 'merchant_id' => $this->merchant->id,
                'rows' => [$this->row(['landmark' => '']), $this->row(['city_id' => ''])],
            ])
            ->assertSessionHasErrors(['rows.1.city_id' => 'اختر المنطقة.'])
            ->assertSessionDoesntHaveErrors(['rows.0.city_id', 'rows.0.landmark']);
    }

    public function test_one_bad_row_saves_nothing_and_points_at_itself(): void
    {
        $this->actingAs($this->owner)
            ->from($this->host().'/shipments/quick')
            ->post($this->host().'/shipments/quick', [
                'mode' => 'merchant', 'merchant_id' => $this->merchant->id,
                'rows' => [
                    $this->row(),
                    $this->row(['recipient_phone' => '123', 'amount' => '25000',
                                'city_id' => $this->city('العشار', 'BSR')]),
                ],
            ])
            ->assertRedirect($this->host().'/shipments/quick')
            ->assertSessionHasErrors(['rows.1.recipient_phone', 'rows.1.amount', 'rows.1.city_id', 'rows'])
            ->assertSessionDoesntHaveErrors(['rows.0.recipient_phone']);

        Tenancy::runFor($this->company, fn () => $this->assertSame(0, Shipment::count()));

        // والصفحة تعود بما كُتب
        $this->actingAs($this->owner)->get($this->host().'/shipments/quick')
            ->assertOk()->assertSee('value="123"', false);
    }

    public function test_a_governorate_table_takes_the_merchant_per_row(): void
    {
        $other = $this->makeMerchant($this->company, 'M0002');
        $mosul = Governorate::where('code', 'NIN')->value('id');

        $this->actingAs($this->owner)
            ->post($this->host().'/shipments/quick', [
                'mode' => 'governorate', 'governorate_id' => $mosul, 'fees_paid_by' => 'customer',
                'rows' => [
                    $this->row(['merchant_id' => $this->merchant->id, 'governorate_id' => null, 'city_id' => $this->city('الموصل', 'NIN')]),
                    $this->row(['merchant_id' => $other->id, 'governorate_id' => null, 'city_id' => $this->city('الموصل', 'NIN')]),
                    $this->row(['merchant_id' => '', 'governorate_id' => null, 'city_id' => $this->city('الموصل', 'NIN')]),
                ],
            ])
            ->assertSessionHasErrors(['rows.2.merchant_id']);

        $this->actingAs($this->owner)
            ->post($this->host().'/shipments/quick', [
                'mode' => 'governorate', 'governorate_id' => $mosul, 'fees_paid_by' => 'customer',
                'rows' => [
                    $this->row(['merchant_id' => $this->merchant->id, 'city_id' => $this->city('الموصل', 'NIN')]),
                    $this->row(['merchant_id' => $other->id, 'city_id' => $this->city('تلعفر', 'NIN')]),
                ],
            ])
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () use ($mosul, $other) {
            $this->assertSame([$mosul], Shipment::distinct()->pluck('governorate_id')->all());
            $this->assertSame(1, Shipment::where('merchant_id', $other->id)->count());
            // لا «من يدفع الأجرة» في الإدخال السريع: الأجرة على التاجر ولو أُرسل غيره
            $this->assertSame(['merchant'], Shipment::distinct()->pluck('fees_paid_by')->all());
        });
    }

    public function test_a_courier_for_all_sends_them_out_at_once(): void
    {
        $courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'مندوب الكرادة', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
        ]));

        $this->actingAs($this->owner)
            ->post($this->host().'/shipments/quick', [
                'mode' => 'merchant', 'merchant_id' => $this->merchant->id,
                'courier_id' => $courier->id, 'rows' => [$this->row(), $this->row(['recipient_phone' => '07709998877'])],
            ])
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'وخرجت مع مندوب الكرادة'));

        Tenancy::runFor($this->company, function () use ($courier) {
            foreach (Shipment::all() as $shipment) {
                $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->status);
                $this->assertSame($courier->id, $shipment->delivery_courier_id);
            }
        });

        // ومن لا يُسند لا يُخرج
        $agent = $this->makeUser($this->company, UserRole::CustomerService);
        $this->actingAs($agent)
            ->post($this->host().'/shipments/quick', [
                'mode' => 'merchant', 'merchant_id' => $this->merchant->id,
                'courier_id' => $courier->id, 'rows' => [$this->row()],
            ])
            ->assertForbidden();
    }

    public function test_limits_and_empty_tables(): void
    {
        $this->actingAs($this->owner)
            ->post($this->host().'/shipments/quick', [
                'mode' => 'merchant', 'merchant_id' => $this->merchant->id,
                'rows' => array_fill(0, 31, $this->row()),
            ])
            ->assertSessionHasErrors('rows');

        $this->actingAs($this->owner)
            ->post($this->host().'/shipments/quick', [
                'mode' => 'merchant', 'merchant_id' => $this->merchant->id,
                'rows' => [['governorate_id' => $this->baghdad()->id, 'amount' => '']],
            ])
            ->assertSessionHasErrors('rows');

        $this->actingAs($this->owner)->get($this->host().'/shipments/quick?mode=governorate')
            ->assertOk()->assertSee('على مستوى المحافظة')->assertSee('data-quick-template', false);

        Tenancy::runFor($this->company, fn () => $this->assertSame(0, Shipment::count()));
    }
}
