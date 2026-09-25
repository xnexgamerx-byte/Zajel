<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\UpdateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\PriceListRule;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * تصحيح بيانات الشحنة: يصحّ ما دام مالها لم يُقيَّد، ولا يبدّل من الأجور إلا
 * ما تغيّر سببه، ويُكتب في سجلّها بما كان وما صار.
 */
class ShipmentEditTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Governorate $basra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
        $this->basra = Governorate::where('code', 'BSR')->firstOrFail();

        // البصرة أغلى من القاعدة العامة: ٨٬٠٠٠ توصيلاً و٤٬٠٠٠ راجعاً
        Tenancy::runFor($this->company, fn () => PriceListRule::create([
            'price_list_id' => $this->merchant->price_list_id, 'to_governorate_id' => $this->basra->id,
            'weight_from_grams' => 0, 'weight_to_grams' => 5000,
            'delivery_fee' => 8000, 'return_fee' => 4000, 'is_active' => true,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
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
            'fees_paid_by'    => 'merchant',
        ], $overrides), $this->owner));
    }

    /** ما يرسله النموذج: بيانات الشحنة كما هي، ثم ما يغيّره المستخدم. */
    private function form(Shipment $shipment, array $changes = []): array
    {
        return array_merge([
            'recipient_name'  => $shipment->recipient_name,
            'recipient_phone' => $shipment->recipient_phone,
            'governorate_id'  => $shipment->governorate_id,
            'city_id'         => $shipment->city_id,
            'address'         => $shipment->address,
            'landmark'        => $shipment->landmark,
            'pieces_count'    => $shipment->pieces_count,
            'weight_grams'    => $shipment->weight_grams,
            'cod_amount'      => $shipment->cod_amount,
            'fees_paid_by'    => $shipment->fees_paid_by,
            'extra_fee'       => $shipment->extra_fee,
            'discount'        => $shipment->discount,
            'delivery_fee'    => '',
        ], $changes);
    }

    private function edit(Shipment $shipment, array $changes, ?User $as = null): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(UpdateShipment::class)
            ->handle($shipment, $this->form($shipment, $changes), $as ?? $this->owner));
    }

    public function test_a_wrong_phone_is_corrected_and_recorded(): void
    {
        $shipment = $this->makeShipment();

        $edited = $this->edit($shipment, ['recipient_phone' => '07809998877', 'address' => 'بغداد - المنصور']);

        $this->assertSame('07809998877', $edited->recipient_phone);
        $this->assertSame('بغداد - المنصور', $edited->address);

        $event = Tenancy::runFor($this->company, fn () => ShipmentEvent::where('shipment_id', $shipment->id)
            ->where('event_type', 'edited')->sole());

        // السجلّ الظاهر يُخفي الهاتف كما تُخفيه الشاشات، والكامل في meta
        $this->assertStringContainsString('هاتف المستلم: …567 ← …877', $event->note);
        $this->assertStringNotContainsString('07809998877', $event->note);
        $this->assertSame('07809998877', $event->meta['changes']['recipient_phone']['to']);
        $this->assertSame($this->owner->id, $event->actor_id);
    }

    public function test_correcting_a_phone_keeps_a_fee_set_by_hand(): void
    {
        $shipment = $this->makeShipment(['delivery_fee' => 3000]);

        $edited = $this->edit($shipment, ['recipient_phone' => '07809998877']);

        // التسعيرة تقول ٥٬٠٠٠، لكن موظّفاً كتب ٣٬٠٠٠ — وتصحيح هاتفٍ لا يمسّها
        $this->assertSame(3000, (int) $edited->delivery_fee);
        $this->assertSame(47_000, (int) $edited->merchant_due);
    }

    public function test_a_new_governorate_is_priced_from_its_rule(): void
    {
        $shipment = $this->makeShipment();
        $this->assertSame(5000, (int) $shipment->delivery_fee);

        $edited = $this->edit($shipment, ['governorate_id' => $this->basra->id, 'address' => 'البصرة - العشار']);

        $this->assertSame(8000, (int) $edited->delivery_fee);
        $this->assertSame(4000, (int) $edited->return_fee);
        $this->assertSame(8000, (int) $edited->total_fees);
        $this->assertSame(42_000, (int) $edited->merchant_due);
    }

    public function test_a_new_amount_recomputes_what_the_merchant_is_owed(): void
    {
        $shipment = $this->makeShipment();

        $edited = $this->edit($shipment, ['cod_amount' => 80_000]);

        $this->assertSame(80_000, (int) $edited->cod_amount);
        $this->assertSame(75_000, (int) $edited->merchant_due);

        // صفرٌ يعني مدفوعاً مسبقاً: يصير مستحقّ التاجر سالباً بأجرته
        $prepaid = $this->edit($edited, ['cod_amount' => 0]);
        $this->assertSame('prepaid', $prepaid->payment_type);
        $this->assertSame(-5000, (int) $prepaid->merchant_due);
    }

    public function test_a_fee_written_in_the_form_wins(): void
    {
        $shipment = $this->makeShipment();

        $edited = $this->edit($shipment, ['delivery_fee' => 6500]);

        $this->assertSame(6500, (int) $edited->delivery_fee);
        $this->assertSame(43_500, (int) $edited->merchant_due);
    }

    public function test_the_governorate_is_fixed_once_the_shipment_reaches_the_hub(): void
    {
        $shipment = $this->makeShipment();
        Tenancy::runFor($this->company, fn () => $shipment->forceFill(['status' => ShipmentStatus::AtHub])->save());

        // بقية البيانات تُصحَّح
        $edited = $this->edit($shipment->refresh(), ['landmark' => 'خلف مول بغداد']);
        $this->assertSame('خلف مول بغداد', $edited->landmark);

        $this->expectException(ValidationException::class);
        $this->edit($edited, ['governorate_id' => $this->basra->id]);
    }

    public function test_a_delivered_shipment_cannot_be_edited(): void
    {
        $shipment = $this->makeShipment();
        Tenancy::runFor($this->company, fn () => $shipment->forceFill(['status' => ShipmentStatus::Delivered])->save());

        $this->actingAs($this->owner)
            ->get($this->host().'/shipments/'.$shipment->id.'/edit')
            ->assertForbidden();

        $this->expectException(ValidationException::class);
        $this->edit($shipment->refresh(), ['recipient_phone' => '07809998877']);
    }

    public function test_saving_without_changes_writes_nothing(): void
    {
        $shipment = $this->makeShipment();

        $this->edit($shipment, []);

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => ShipmentEvent::where('shipment_id', $shipment->id)
            ->where('event_type', 'edited')->count()));
    }

    public function test_the_screen_edits_through_http(): void
    {
        $shipment = $this->makeShipment();

        $this->actingAs($this->owner)
            ->get($this->host().'/shipments/'.$shipment->id.'/edit')
            ->assertOk()
            ->assertSee('تعديل الشحنة')
            ->assertSee('value="07801234567"', false);

        $this->actingAs($this->owner)
            ->put($this->host().'/shipments/'.$shipment->id, $this->form($shipment, ['recipient_name' => 'علي حسين جاسم']))
            ->assertRedirect($this->host().'/shipments/'.$shipment->id);

        $this->assertSame('علي حسين جاسم', Tenancy::runFor($this->company, fn () => $shipment->refresh()->recipient_name));

        $this->actingAs($this->owner)
            ->get($this->host().'/shipments/'.$shipment->id)
            ->assertSee('تعديل البيانات')
            ->assertSee('اسم المستلم: علي حسين ← علي حسين جاسم');
    }

    public function test_editing_needs_its_own_permission(): void
    {
        $shipment = $this->makeShipment();

        // خدمة العملاء: تقرأ وتُنشئ وتُجيب، ولا تغيّر ديناراً — إلا إن مُنحت الصلاحية
        $agent = $this->makeUser($this->company, UserRole::CustomerService);
        $this->actingAs($agent)
            ->get($this->host().'/shipments/'.$shipment->id.'/edit')
            ->assertForbidden();
        $this->actingAs($agent)
            ->get($this->host().'/shipments/'.$shipment->id)
            ->assertDontSee('تعديل البيانات');

        $operations = $this->makeUser($this->company, UserRole::Operations);
        $this->actingAs($operations)
            ->get($this->host().'/shipments/'.$shipment->id.'/edit')
            ->assertOk();
    }
}
