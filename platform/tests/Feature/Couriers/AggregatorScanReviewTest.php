<?php

namespace Tests\Feature\Couriers;

use App\Enums\UserRole;
use App\Models\AggregatorJob;
use App\Models\Shipment;
use App\Services\Aggregator\AggregatorScanReview;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «مراجعة مسح الوسيط» (docs/plan/121): لكلّ شحنةٍ مع المندوب الوسيط، أيقرأ مندوبهم طردها، ولماذا لا — وما يُصلحها.
 */
class AggregatorScanReviewTest extends TestCase
{
    use AggregatorFixtures, RefreshDatabase;

    private function url(string $path = ''): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/aggregator/review'.$path;
    }

    /** @return array<int, list<string>> معرّف الشحنة ← مشكلاتها */
    private function problems(array $verified = []): array
    {
        return Tenancy::runFor($this->company, fn () => app(AggregatorScanReview::class)->rows($this->link->refresh(), $verified)
            ->mapWithKeys(fn ($row) => [$row['shipment']->id => $row['problems']])->all());
    }

    private function printLabels(Shipment ...$shipments): void
    {
        $ids = implode('&', array_map(fn ($s) => 'ids[]='.$s->id, $shipments));
        $this->actingAs($this->staff)->get('http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/shipments/labels?'.$ids)
            ->assertOk();
    }

    private function forceFill(Shipment $shipment, array $values): void
    {
        Tenancy::runFor($this->company, fn () => Shipment::query()->whereKey($shipment->id)->toBase()->update($values));
    }

    public function test_every_shipment_with_the_intermediary_is_classified_by_why_their_scan_fails(): void
    {
        $this->fakeApi();

        $ok = $this->ship();
        $paper = $this->ship(extra: ['barcode' => '90001234']);   // ورقة الدفتر وحدها
        $paperFixed = $this->ship(extra: ['barcode' => '90001235']); // طُبع له وصلٌ برقمهم
        $old = $this->ship();                                       // وصلٌ طُبع قبل البادئة
        $preOld = $this->ship(extra: ['barcode' => '90001236']);   // وصلٌ طُبع برمز الدفتر قبل الإصلاح
        $noLabel = $this->ship();                                   // لم يُطبع له شيء
        $this->artisan('aggregator:sync')->assertSuccessful();

        // منطقةٌ غير مربوطة عندهم: يرفضه الإرسال، ويبقى يُعاد بسببه
        $failed = Tenancy::runFor($this->company, function () {
            return $this->ship(extra: ['governorate_id' => $this->baghdad()->id, 'city_id' => $this->area('المنصور')]);
        });
        $this->artisan('aggregator:sync')->assertSuccessful();

        // أُسند والإرسال مطفأ: لا عمل له
        Tenancy::runFor($this->company, fn () => $this->link->forceFill(['sends_orders' => false])->save());
        $notSent = $this->ship();
        Tenancy::runFor($this->company, fn () => $this->link->forceFill(['sends_orders' => true])->save());

        // مع مندوبٍ آخر: ليست من المراجعة
        $other = $this->ship($this->courier('مندوب الشركة'));

        $this->printLabels($ok, $paperFixed);
        $this->forceFill($old, ['printed_at' => '2026-11-01 10:00:00']);
        $this->forceFill($preOld, ['printed_at' => '2026-11-04 10:00:00']);

        $problems = $this->problems();

        $this->assertSame([], $problems[$ok->id]);
        $this->assertSame([], $problems[$paperFixed->id], 'وصلٌ برقمهم على الطرد');
        $this->assertSame(['paper'], $problems[$paper->id]);
        $this->assertSame(['old_label'], $problems[$old->id]);
        $this->assertSame(['old_label'], $problems[$preOld->id]);
        $this->assertSame(['no_label'], $problems[$noLabel->id]);
        $this->assertSame(['failed', 'no_label'], $problems[$failed->id]);
        $this->assertSame(['not_sent', 'no_label'], $problems[$notSent->id]);
        $this->assertArrayNotHasKey($other->id, $problems);

        // وما طُبع يُحفظ رمزه كما طُبع
        $this->assertSame('ZJL_'.$paperFixed->number, $this->fresh($paperFixed)->label_code);

        // الصفحة: لكلّ تاجر، والسبب، وطباعة ما يحتاج وصلاً
        $page = $this->actingAs($this->staff)->get($this->url())->assertOk();
        $page->assertSee('مراجعة مسح الوسيط')
            ->assertSee('على الطرد ورقة الدفتر وحدها')
            ->assertSee('وصلها المطبوع بغير رقمهم')
            ->assertSee('لم تُرسل إلى نظامهم')
            ->assertSee('غير مربوطة بمدينةٍ عندهم')
            ->assertSee('ZJL_'.$paper->number)
            ->assertSee('90001234')
            ->assertDontSee('>'.$ok->number.'</a>', false);

        // زرّ الطباعة: ما يحتاج وصلاً وحده
        preg_match_all('/ids%5B\d+%5D=(\d+)/', $page->getContent(), $m);
        $this->assertEqualsCanonicalizing(
            array_map(fn ($s) => (string) $s->id, [$paper, $old, $preOld, $noLabel, $failed, $notSent]), $m[1]);

        $this->actingAs($this->staff)->get($this->url('?all=1'))->assertOk()->assertSee('>'.$ok->number.'</a>', false);

        // بعد طباعة وصولها: تُقرأ
        $this->printLabels($paper, $old, $preOld, $noLabel);
        $after = $this->problems();
        foreach ([$paper, $old, $preOld, $noLabel] as $shipment) {
            $this->assertSame([], $after[$shipment->id], $shipment->number);
        }
    }

    public function test_resend_queues_what_never_reached_them_and_retries_what_failed(): void
    {
        $this->fakeApi();

        Tenancy::runFor($this->company, fn () => $this->link->forceFill(['sends_orders' => false])->save());
        $notSent = $this->ship();
        Tenancy::runFor($this->company, fn () => $this->link->forceFill(['sends_orders' => true])->save());

        $failed = Tenancy::runFor($this->company, function () {
            return $this->ship(extra: ['governorate_id' => $this->baghdad()->id, 'city_id' => $this->area('المنصور')]);
        });
        $this->artisan('aggregator:sync')->assertSuccessful();
        $retrying = Tenancy::runFor($this->company, fn () => AggregatorJob::where('shipment_id', $failed->id)->first());
        $this->assertSame('pending', $retrying->status);
        $this->assertTrue($retrying->next_attempt_at->isFuture());

        $this->actingAs($this->staff)->post($this->url('/resend'))->assertSessionHas('success');

        $jobs = Tenancy::runFor($this->company, fn () => AggregatorJob::query()->get()->keyBy('shipment_id'));
        $this->assertSame('pending', $jobs[$notSent->id]->status, 'كُتب له عمل');
        $this->assertFalse($jobs[$failed->id]->next_attempt_at->isFuture(), 'ويُعاد الآن');
        $this->assertSame(0, (int) $jobs[$failed->id]->attempts);

        // يُرسل ما لم يصلهم
        $this->artisan('aggregator:sync')->assertSuccessful();
        $this->assertNotNull($this->fresh($notSent)->aggregator_order_id);

        // والإرسال مطفأ: لا يُرسل شيء
        Tenancy::runFor($this->company, fn () => $this->link->forceFill(['sends_orders' => false])->save());
        $this->actingAs($this->staff)->post($this->url('/resend'))->assertSessionHasErrors('resend');
    }

    public function test_verify_asks_their_system_and_adopts_an_order_they_have_but_we_lost(): void
    {
        $this->fakeApi();
        $there = $this->ship();
        $this->artisan('aggregator:sync')->assertSuccessful();
        $gone = $this->ship();
        $this->artisan('aggregator:sync')->assertSuccessful();

        // أُنشئ عندهم ولم يصلنا رقمه
        Tenancy::runFor($this->company, fn () => $this->link->forceFill(['sends_orders' => false])->save());
        $lost = $this->ship();
        Tenancy::runFor($this->company, fn () => $this->link->forceFill(['sends_orders' => true])->save());

        $asked = [];
        Http::fake([
            self::BASE.'/aggre/login' => Http::response(['status' => true, 'errNum' => 'S000', 'msg' => 'ok', 'data' => ['token' => 'agg-tok']]),
            self::BASE.'/aggre/check-order-exists-by-company-order-number*' => function (HttpRequest $request) use ($there, $lost, &$asked) {
                $asked[] = $id = $request['company-order-id'];
                $orders = match ($id) {
                    'ZJL_'.$there->number => [['id' => '7001', 'company_order_id' => $id]],
                    'ZJL_'.$lost->number => [['id' => '7003', 'company_order_id' => $id]],
                    default => [],
                };

                return Http::response(['status' => true, 'errNum' => 'S000', 'msg' => 'ok', 'data' => ['order' => $orders]]);
            },
        ]);

        $this->actingAs($this->staff)->post($this->url('/verify'))->assertSessionHas('success');

        $this->assertEqualsCanonicalizing(['ZJL_'.$there->number, 'ZJL_'.$gone->number, 'ZJL_'.$lost->number], $asked);
        $this->assertSame('7003', $this->fresh($lost)->aggregator_order_id, 'رُبط بطلبهم فلا يُنشأ ثانيةً');

        $page = $this->actingAs($this->staff)->get($this->url('?all=1'))->assertOk();
        $page->assertSee('نظامهم لا يجدها برقمها')->assertSee('غير موجودة')->assertSee('موجودة');

        $verified = Tenancy::runFor($this->company, fn () => cache()->get('aggregator-review-verified:'.$this->company->id));
        $this->assertEquals([$there->id => true, $gone->id => false, $lost->id => true], $verified);
        $this->assertContains('missing', $this->problems($verified)[$gone->id]);

        // سُئل عن الكلّ: لا يُسأل ثانيةً حتى يُمسح الجواب
        $asked = [];
        $this->actingAs($this->staff)->post($this->url('/verify'))->assertSessionHas('success');
        $this->assertSame([], $asked);
        $this->actingAs($this->staff)->post($this->url('/forget'))->assertSessionHas('success');
        $this->actingAs($this->staff)->post($this->url('/verify'));
        $this->assertCount(3, $asked);
    }

    public function test_only_the_company_owner_screen_and_nothing_without_a_chosen_courier(): void
    {
        $this->fakeApi();
        $this->ship();

        $clerk = $this->makeUser($this->company, UserRole::Accountant);
        $this->actingAs($clerk)->get($this->url())->assertForbidden();

        Tenancy::runFor($this->company, fn () => $this->link->forceFill(['courier_id' => null])->save());
        $this->assertSame([], $this->problems());
        $this->actingAs($this->staff)->get($this->url())->assertOk()->assertSee('لم يُختر المندوب الوسيط');
    }
}
