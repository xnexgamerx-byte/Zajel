<?php

namespace Tests\Feature\Couriers;

use App\Enums\Feature;
use App\Models\Shipment;
use App\Support\Code128;
use App\Support\QrCode;
use App\Support\ScanCode;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * باركود الوصل لشركةٍ مربوطةٍ بوسيط (docs/plan/109): يحمل بادئتها قبل رقم الوصل (ZJL_000001) كما يطلبه نظامهم،
 * وكلّ ماسحٍ عندنا يقرؤه رقم وصل. وبلا ربطٍ مفعّل يبقى الرقم وحده.
 */
class AggregatorBarcodeTest extends TestCase
{
    use AggregatorFixtures, RefreshDatabase;

    private function labels(int $id): string
    {
        return $this->actingAs($this->staff)->get('http://'.$this->company->slug.'.'.config('zajel.tenant_domain')
            .'/shipments/labels?ids[]='.$id)->assertOk()->getContent();
    }

    public function test_the_label_barcode_carries_their_prefix_and_without_the_link_only_the_number(): void
    {
        $this->fakeApi();
        $shipment = $this->ship();

        $page = $this->labels($shipment->id);
        $this->assertStringContainsString('>ZJL_'.$shipment->number.'<', $page, 'تحت الباركود ما يحمله');
        $this->assertStringContainsString('alt="ZJL_'.$shipment->number.'"', $page, 'ورمز QR يحمل الرمز نفسه');
        $this->assertStringNotContainsString('alt="رمز التتبّع"', $page);
        $this->assertStringContainsString(QrCode::dataUri('ZJL_'.$shipment->number), $page);

        // الميزة مغلقة: رقم الوصل وحده كما كان
        $this->setFeature($this->company, Feature::Aggregator, enabled: false);
        $page = $this->labels($shipment->id);
        $this->assertStringNotContainsString('ZJL_', $page);
        $this->assertStringContainsString('>'.$shipment->number.'<', $page);
        $this->assertStringContainsString('alt="رمز التتبّع"', $page, 'رابط التتبّع كما كان');
    }

    /**
     * وصلٌ من دفتر الوصولات (docs/plan/121): ورقته على الطرد برمز الدفتر، ونظامهم يعرف الطلب برقم وصلنا ببادئته.
     * فوصله المطبوع من النظام يحمل ما يعرفونه، ورمز الدفتر مكتوبٌ فيه — وبلا ربطٍ يبقى رمز الدفتر كما كان.
     */
    public function test_a_preprinted_waybill_label_carries_what_the_intermediary_knows(): void
    {
        $this->fakeApi();
        $shipment = $this->ship(extra: ['barcode' => '90001234']);
        $this->assertNotSame('90001234', $shipment->number);

        $page = $this->labels($shipment->id);
        $this->assertStringContainsString(Code128::dataUri('ZJL_'.$shipment->number), $page, 'الباركود برقمهم');
        $this->assertStringContainsString('>ZJL_'.$shipment->number.'<', $page);
        $this->assertStringContainsString(QrCode::dataUri('ZJL_'.$shipment->number), $page);
        $this->assertStringNotContainsString(Code128::dataUri('90001234'), $page, 'لا باركود برمز الدفتر');
        $this->assertStringContainsString('الوصل المطبوع <span class="num">90001234</span>', $page, 'ورمز الدفتر مكتوب');

        // وماسحاتنا تقرأ الاثنين: ورقة الدفتر ووصل الوسيط
        $host = 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
        foreach (['90001234', 'ZJL_'.$shipment->number] as $scanned) {
            $this->actingAs($this->staff)->getJson($host.'/shipments/scan/lookup?number='.urlencode($scanned))
                ->assertOk()->assertJson(['id' => $shipment->id]);
        }

        // بلا ربط: رمز الدفتر كما كان
        $this->setFeature($this->company, Feature::Aggregator, enabled: false);
        $page = $this->labels($shipment->id);
        $this->assertStringContainsString(Code128::dataUri('90001234'), $page);
        $this->assertStringNotContainsString('الوصل المطبوع', $page);
    }

    public function test_our_scanners_read_the_prefixed_barcode_as_the_waybill_number(): void
    {
        $this->fakeApi();
        $shipment = $this->ship();
        $host = 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');

        foreach (['ZJL_'.$shipment->number, 'ZAJ_'.$shipment->number, 'زشت_'.$shipment->number] as $scanned) {
            $read = ScanCode::read($scanned);
            $this->assertSame($shipment->number, $read->code, $scanned);
            $this->assertTrue($read->prefixed);

            // ماسح المخزن، وخانة البحث
            $this->actingAs($this->staff)->getJson($host.'/shipments/scan/lookup?number='.urlencode($scanned))
                ->assertOk()->assertJson(['id' => $shipment->id]);
        }

        $this->assertSame([$shipment->id], Tenancy::runFor($this->company, fn () => Shipment::query()
            ->search('ZJL_'.$shipment->number)->pluck('id')->all()));

        // الرقم وحده والرابط كما كانا
        $this->assertFalse(ScanCode::read($shipment->number)->prefixed);
        $this->assertSame($shipment->number, ScanCode::read($shipment->number)->code);
    }
}
