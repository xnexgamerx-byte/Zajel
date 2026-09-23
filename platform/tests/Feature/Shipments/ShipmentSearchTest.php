<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\CreateShipment;
use App\Models\Company;
use App\Models\Shipment;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * البحث اتّحادُ استعلاماتٍ لا «أو» واحدة (Shipment::scopeSearch)، وكل
 * فرعٍ منها استعلامٌ مستقلّ يجب أن يجد ما وُضع له، وأن يُركَّب مع شروط
 * المستدعي، وألّا يُظهر شحنة شركةٍ أخرى.
 *
 * العزل هنا مضاعَف: كل فرعٍ يمرّ بنطاق الشركة، والاستعلام الخارجي يمرّ
 * به ثانيةً. نُزع النطاق من الفروع تجربةً فبقي الاختبار أخضر — الخارجيّ
 * وحده كافٍ — فالحارس على النتيجة التي يراها المستخدم لا على الطبقة.
 */
class ShipmentSearchTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->alpha = $this->makeCompany('alpha', 'ألفا');
        $this->beta = $this->makeCompany('beta', 'بيتا');
    }

    private function ship(Company $company, array $overrides = []): Shipment
    {
        $merchant = $this->makeMerchant($company);
        $actor = $this->makeUser($company);

        return Tenancy::runFor($company, fn () => app(CreateShipment::class)->handle(array_merge([
            'merchant_id'     => $merchant->id,
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد',
            'landmark'        => 'قرب الجامع',
            'cod_amount'      => 25_000,
        ], $overrides), $actor));
    }

    private function found(Company $company, string $term): array
    {
        return Tenancy::runFor($company, fn () => Shipment::search($term)->pluck('id')->all());
    }

    public function test_every_field_customer_service_types_finds_the_shipment(): void
    {
        $shipment = $this->ship($this->alpha, [
            'recipient_name'      => 'زينب كاظم',
            'recipient_phone'     => '07711112222',
            'recipient_phone_alt' => '07733334444',
            'merchant_reference'  => 'ORD-7781',
        ]);

        foreach ([
            'رقم الوصل'     => $shipment->number,
            'الباركود'      => $shipment->barcode,
            'رقم طلب التاجر' => 'ORD-7781',
            'الهاتف'        => '07711112222',
            'الهاتف البديل' => '07733334444',
            'بداية الاسم'   => 'زينب',
            'بداية الرقم'   => substr($shipment->number, 0, 3),
        ] as $label => $term) {
            $this->assertSame([$shipment->id], $this->found($this->alpha, $term), "البحث بـ{$label}");
        }

        // حرفان لا يكفيان للبحث الجزئي — وإلا مسح الفهرسَ كلّه
        $this->assertSame([], $this->found($this->alpha, 'زي'));
        $this->assertSame([], $this->found($this->alpha, '07711'));
    }

    public function test_no_branch_of_the_search_reaches_another_company(): void
    {
        $fields = [
            'recipient_name'      => 'مشترك الاسم',
            'recipient_phone'     => '07799990000',
            'recipient_phone_alt' => '07788880000',
            'merchant_reference'  => 'SAME-1',
        ];

        $ours = $this->ship($this->alpha, $fields);
        $theirs = $this->ship($this->beta, $fields);

        foreach (['07799990000', '07788880000', 'SAME-1', 'مشترك', $theirs->number, $theirs->barcode] as $term) {
            $this->assertNotContains($theirs->id, $this->found($this->alpha, $term), "تسرّب عبر «{$term}»");
        }

        $this->assertSame([$ours->id], $this->found($this->alpha, '07799990000'));
        $this->assertSame(
            [$ours->id, $theirs->id],
            Tenancy::runAsPlatform(fn () => Shipment::search('07799990000')->orderBy('id')->pluck('id')->all()),
        );
    }

    public function test_a_deleted_shipment_is_not_found(): void
    {
        $shipment = $this->ship($this->alpha, ['recipient_phone' => '07755556666']);

        Tenancy::runFor($this->alpha, fn () => $shipment->delete());

        $this->assertSame([], $this->found($this->alpha, '07755556666'));
    }

    public function test_the_search_narrows_the_callers_own_conditions(): void
    {
        $first = $this->ship($this->alpha, ['recipient_phone' => '07766667777']);
        $second = $this->ship($this->alpha, ['recipient_phone' => '07766667777', 'cod_amount' => 90_000]);

        Tenancy::runFor($this->alpha, function () use ($second) {
            $this->assertSame(2, Shipment::search('07766667777')->count());
            $this->assertSame(
                [$second->id],
                Shipment::query()->where('cod_amount', 90_000)->search('07766667777')->pluck('id')->all(),
            );
        });
    }
}
