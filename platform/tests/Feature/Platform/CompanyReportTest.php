<?php

namespace Tests\Feature\Platform;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\DeleteShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «تقرير الشركة» في لوحة النواة (docs/plan/111): شحناتها وربحها وتقويمها في المدّة، وما حُذف وما تغيّر سعره
 * ومن فعله — لمدير المنصّة وحده، ومن هذه الشركة وحدها.
 */
class CompanyReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->admin = Tenancy::runAsPlatform(fn () => User::create(['name' => 'مدير المنصّة', 'phone' => '07700000000',
            'password' => 'secret-pass', 'role' => UserRole::PlatformAdmin, 'is_active' => true]));
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->other = $this->makeCompany('other', 'أخرى');
    }

    /** @return array{0: Shipment, 1: Shipment, 2: Shipment} سُلّمت، وتغيّر سعرها، وحُذفت */
    private function fill(Company $company, string $tag): array
    {
        $owner = $this->makeUser($company);
        $merchant = $this->makeMerchant($company, 'M'.$tag);

        return Tenancy::runFor($company, function () use ($owner, $merchant, $tag) {
            $courier = Courier::create(['code' => 'C'.$tag, 'name' => 'مندوب', 'phone' => '077211122'.$tag, 'type' => 'delivery', 'status' => 'active']);
            $make = fn (string $name) => app(CreateShipment::class)->handle(['merchant_id' => $merchant->id, 'recipient_name' => $name,
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 50_000], $owner);

            $delivered = $make('سُلّمت');
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($delivered->refresh(), $status, $owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier->id : null]);
            }

            $repriced = $make('تغيّر سعرها');
            ShipmentEvent::create(['shipment_id' => $repriced->id, 'from_status' => 'created', 'to_status' => 'created',
                'event_type' => 'edited', 'actor_type' => 'user', 'actor_id' => $owner->id, 'actor_name' => 'موظّفة '.$tag,
                'note' => 'المبلغ', 'meta' => ['changes' => ['cod_amount' => ['from' => 50_000, 'to' => 35_000]]]]);
            // تعديلٌ لا يمسّ السعر لا يُعدّ
            ShipmentEvent::create(['shipment_id' => $repriced->id, 'from_status' => 'created', 'to_status' => 'created',
                'event_type' => 'edited', 'actor_type' => 'user', 'actor_name' => 'موظّفة', 'note' => 'العنوان',
                'meta' => ['changes' => ['address' => ['from' => 'أ', 'to' => 'ب']]]]);

            $deleted = $make('حُذفت');
            app(DeleteShipment::class)->delete($deleted->refresh(), 'طلب مكرر من التاجر', $owner);

            return [$delivered, $repriced, $deleted];
        });
    }

    public function test_the_platform_admin_sees_the_companys_numbers_calendar_deletions_and_price_changes(): void
    {
        [$delivered, $repriced, $deleted] = $this->fill($this->company, '1');
        [, , $foreign] = $this->fill($this->other, '2');

        $this->actingAs($this->admin)->get('/admin/companies/'.$this->company->id)->assertOk()->assertSee('تقرير الشركة');

        $page = $this->get('/admin/companies/'.$this->company->id.'/report')->assertOk()
            ->assertSee('تقرير الزاجل')->assertSee('التقويم')
            // ما تغيّر سعره: المبلغ قبل وبعد ومن غيّره، والتعديل الذي لا يمسّ السعر لا يظهر
            ->assertSee($repriced->number)->assertSee('المبلغ: 50,000 ← 35,000')->assertSee('موظّفة 1')
            // ما حُذف وسببه
            ->assertSee($deleted->number)->assertSee('طلب مكرر من التاجر')
            // ولا شيء من الشركة الأخرى
            ->assertDontSee('موظّفة 2');

        $report = $page->viewData('report');
        $this->assertSame(3, $report['shipments']['created']);
        $this->assertSame(1, $report['shipments']['delivered']);
        $this->assertSame(1, $report['shipments']['deleted']);
        $this->assertCount(1, $report['prices']);
        $this->assertCount(1, $report['deleted']);
        $this->assertSame(3, collect($report['calendar'])->sum('created'));

        // مدّةٌ ماضية بلا شيء
        $empty = $this->get('/admin/companies/'.$this->company->id.'/report?from=2020-01-01&to=2020-01-31')->assertOk()
            ->assertSee('لم تُحذف شحنةٌ في هذه المدّة')->assertSee('لم يتغيّر سعر شحنةٍ في هذه المدّة');
        $this->assertSame(0, $empty->viewData('report')['shipments']['created']);

        // مدّةٌ طويلة: بلا تقويم
        $this->get('/admin/companies/'.$this->company->id.'/report?from=2025-01-01&to=2026-12-31')->assertOk()
            ->assertSee('أطول من ٩٢ يوماً');
    }

    public function test_platform_support_cannot_see_a_companys_money(): void
    {
        $support = Tenancy::runAsPlatform(fn () => User::create(['name' => 'دعم', 'phone' => '07700000009',
            'password' => 'secret-pass', 'role' => UserRole::PlatformSupport, 'is_active' => true]));

        $this->actingAs($support)->get('/admin/companies/'.$this->company->id.'/report')->assertForbidden();
        $this->actingAs($support)->get('/admin/companies/'.$this->company->id)->assertOk()->assertDontSee('تقرير الشركة');
    }
}
