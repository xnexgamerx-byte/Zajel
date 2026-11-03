<?php

namespace Tests\Feature\Couriers;

use App\Enums\Feature;
use App\Models\Shipment;
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

        // الميزة مغلقة: رقم الوصل وحده كما كان
        $this->setFeature($this->company, Feature::Aggregator, enabled: false);
        $page = $this->labels($shipment->id);
        $this->assertStringNotContainsString('ZJL_', $page);
        $this->assertStringContainsString('>'.$shipment->number.'<', $page);
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
