<?php

namespace Tests\Feature\Money;

use App\Actions\Profits\ManageCollectionBatch;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Settlements\DeleteDraftSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\CashBox;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchItem;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Finance\JournalCheck;
use App\Support\Finance\Accounts as A;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * وجبات التحصيل، الإصدار الثاني (docs/plan/68 §٧، المرحلة الثانية): الوجبة أولاً، والفرق
 * يُكتب عند الاعتماد — العجز ذمّةٌ على المندوب أو شطبٌ بصلاحية، والزيادة معلَّقة — والتصحيح
 * بعد الاعتماد بفرقه، والإلغاء بسجلّه. والمقياس: الصندوق والدفتر والقيود تبقى متطابقة.
 */
class CollectionBatchesV2Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $ahmed;

    private Courier $ali;

    private CashBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->ahmed, $this->ali, $this->box] = Tenancy::runFor($this->company, fn () => [
            Courier::create(['code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500]),
            Courier::create(['code' => 'C2', 'name' => 'علي الكعبي', 'phone' => '07720000002', 'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1000]),
            CashBox::create(['code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 0, 'is_active' => true]),
        ]);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function deliver(Courier $courier, int $cod): void
    {
        Tenancy::runFor($this->company, function () use ($courier, $cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
            ], $this->owner);

            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier->id : null,
                ]);
            }
        });
    }

    private function settled(Courier $courier, array $cods): CourierSettlement
    {
        foreach ($cods as $cod) {
            $this->deliver($courier, $cod);
        }

        return Tenancy::runFor($this->company, fn () => app(ConfirmCourierSettlement::class)->handle(
            app(BuildCourierSettlement::class)->handle($courier->refresh(), $this->owner), $this->owner, box: $this->box,
        ));
    }

    /** وجبةٌ من كشوفٍ مُقفَلة، بصندوق القاصة */
    private function batch(array $settlements, ?User $actor = null): CollectionBatch
    {
        return Tenancy::runFor($this->company, fn () => app(ManageCollectionBatch::class)->create(
            array_map(fn ($s) => $s->id, $settlements), null, 'week', now()->subDays(6), now(), null, $actor ?? $this->owner, $this->box->id,
        ));
    }

    private function item(CollectionBatch $batch, Courier $courier): CollectionBatchItem
    {
        return Tenancy::runFor($this->company, fn () => CollectionBatchItem::where('collection_batch_id', $batch->id)
            ->where('courier_id', $courier->id)->where('kind', 'settlement')->firstOrFail());
    }

    private function fresh(CollectionBatch $batch): CollectionBatch
    {
        return Tenancy::runFor($this->company, fn () => $batch->refresh());
    }

    private function boxBalance(): int
    {
        return (int) Tenancy::runFor($this->company, fn () => $this->box->refresh()->balance);
    }

    private function cashInHand(Courier $courier): int
    {
        return (int) Tenancy::runFor($this->company, fn () => $courier->refresh()->cash_in_hand);
    }

    private function balance(string $account, array $where = []): int
    {
        return (int) DB::table('journal_lines')->where('company_id', $this->company->id)->where('account', $account)
            ->where($where)->sum(DB::raw('debit - credit'));
    }

    /** كل فحوص المطابقة سليمة */
    private function assertJournalOk(): void
    {
        $checks = Tenancy::runFor($this->company, fn () => collect(app(JournalCheck::class)->run())->pluck('status', 'key')->all());
        $this->assertSame([], array_keys(array_filter($checks, fn ($s) => $s !== 'ok')), json_encode($checks, JSON_UNESCAPED_UNICODE));
    }

    private function received(CollectionBatch $batch, CollectionBatchItem $item, int $amount, ?string $reason = null, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->owner)->post($this->host()."/profits/batches/{$batch->id}/items/{$item->id}/received",
            array_filter(['received_cash' => $amount, 'reason' => $reason], fn ($v) => $v !== null));
    }

    public function test_batch_first_confirms_each_settlement_when_its_cash_is_counted_and_books_the_shortage_on_the_courier(): void
    {
        $this->deliver($this->ahmed, 50_000);
        $this->deliver($this->ahmed, 30_000);

        $this->actingAs($this->owner)->get($this->host().'/profits/batches/create?mode=couriers')
            ->assertOk()->assertSee('أحمد الساعدي')->assertSee('الوجبة أولاً');

        $this->actingAs($this->owner)->post($this->host().'/profits/batches', [
            'mode' => 'couriers', 'courier_ids' => [$this->ahmed->id], 'cash_box_id' => $this->box->id,
        ])->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'COL-0001'));

        $batch = Tenancy::runFor($this->company, fn () => CollectionBatch::where('number', 'COL-0001')->firstOrFail());
        $item = $this->item($batch, $this->ahmed);
        $settlement = Tenancy::runFor($this->company, fn () => CourierSettlement::findOrFail($item->courier_settlement_id));

        $this->assertSame(['awaiting_cash', 'pending_cash', 'draft'], [$batch->status, $item->status, $settlement->status]);
        $this->actingAs($this->owner)->get($this->host()."/profits/batches/{$batch->id}")->assertOk()->assertSee('استلام النقد')->assertSee('القاصة الرئيسية');
        $this->assertSame(0, $this->boxBalance());
        $expected = (int) $settlement->net_amount;
        $this->assertSame(80_000 - 3_000, $expected);

        // لا يُعرض ثانيةً لوجبةٍ أخرى، ولا يُحذف كشفه المسودّة من تحتها
        $this->actingAs($this->owner)->get($this->host().'/profits/batches/create?mode=couriers')->assertDontSee('أحمد الساعدي');
        Tenancy::runFor($this->company, function () use ($settlement) {
            $this->expectValidation(fn () => app(DeleteDraftSettlement::class)->handle($settlement, $this->owner), 'COL-0001');
        });

        // لا اعتماد ونقده لم يُستلم
        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/approve")->assertSessionHasErrors('batch');

        $before = $this->cashInHand($this->ahmed);
        $this->received($batch, $item, $expected - 5_000)->assertSessionHasNoErrors();

        $settlement = Tenancy::runFor($this->company, fn () => $settlement->refresh());
        $item = $this->item($batch, $this->ahmed);
        $this->assertSame('confirmed', $settlement->status);
        $this->assertSame(['short', 'discrepancy'], [$item->status, $this->fresh($batch)->status]);
        // الكشف أُقفل في صندوق الوجبة بما فيه؛ والعجز لم يُكتب بعد
        $this->assertSame($expected, $this->boxBalance());
        $afterConfirm = $this->cashInHand($this->ahmed);
        // عهدته صُفّيت بالكشف: النقد للصندوق، وعمولته احتفظ بها
        $this->assertSame([80_000, 0], [$before, $afterConfirm]);

        $this->actingAs($this->owner)->get($this->host()."/profits/batches/{$batch->id}")->assertOk()->assertSee('عالِج العجز');

        // عجزٌ لم يُعالَج يمنع الاعتماد
        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/approve")->assertSessionHasErrors('batch');
        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/items/{$item->id}/resolve", ['resolution' => 'courier_debt'])
            ->assertSessionHasNoErrors();
        $this->assertSame('awaiting_reconciliation', $this->fresh($batch)->status);

        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/approve")->assertSessionHasNoErrors();

        $batch = $this->fresh($batch);
        $this->assertSame('approved', $batch->status);
        $this->assertSame(-5_000, (int) $batch->discrepancy);
        // العجز خرج من الصندوق إلى ذمّة المندوب
        $this->assertSame($expected - 5_000, $this->boxBalance());
        $this->assertSame($afterConfirm + 5_000, $this->cashInHand($this->ahmed));
        $this->assertSame(5_000, $this->balance(A::SHORTAGES, ['courier_id' => $this->ahmed->id]));
        $this->assertSame($this->boxBalance(), $this->balance(A::CASH, ['cash_box_id' => $this->box->id]));
        $this->assertJournalOk();
        $this->actingAs($this->owner)->get($this->host()."/profits/batches/{$batch->id}")->assertOk()->assertSee('ذمّة على المندوب')->assertDontSee('إلغاء الوجبة');
        $this->actingAs($this->owner)->get($this->host()."/profits/batches/{$batch->id}/print")->assertOk();

        // كشفه المالي يقول ما عليه
        $this->actingAs($this->owner)->get($this->host()."/profits/couriers/{$this->ahmed->id}")
            ->assertOk()->assertSee('العجز عليه')->assertSee('5,000')->assertSee('COL-0001');

        // ويُحصَّل في وجبةٍ لاحقة: الذمّة تُصفَّر والنقد يدخل الصندوق
        $next = $this->batch([$this->settled($this->ali, [20_000])]);
        $boxBefore = $this->boxBalance();
        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$next->id}/remainders", [
            'remainder_of_item_id' => $item->id, 'amount' => 5_000, 'note' => 'سلّم الباقي',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$next->id}/approve")->assertSessionHasNoErrors();

        $this->assertSame($boxBefore + 5_000, $this->boxBalance());
        $this->assertSame(0, $this->balance(A::SHORTAGES, ['courier_id' => $this->ahmed->id]));
        $this->assertSame($afterConfirm, $this->cashInHand($this->ahmed));
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => $item->refresh()->outstanding()));
        $this->assertJournalOk();
    }

    public function test_writing_off_a_shortage_needs_its_permission_and_lands_in_the_write_off_expense(): void
    {
        $batch = $this->batch([$this->settled($this->ahmed, [40_000])]);
        $item = $this->item($batch, $this->ahmed);
        $accountant = $this->makeUser($this->company, UserRole::Accountant);

        $this->received($batch, $item, (int) $item->expected_cash - 2_000)->assertSessionHasNoErrors();
        $inHand = $this->cashInHand($this->ahmed);

        $this->actingAs($accountant)->post($this->host()."/profits/batches/{$batch->id}/items/{$item->id}/resolve", [
            'resolution' => 'written_off', 'resolution_reason' => 'سرقة',
        ])->assertSessionHasErrors('resolution');
        // ولا يعتمد باستثناءٍ من لا يملك الشطب
        $this->actingAs($accountant)->post($this->host()."/profits/batches/{$batch->id}/approve", ['exception' => 'لاحقاً'])
            ->assertSessionHasErrors('batch');

        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/items/{$item->id}/resolve", ['resolution' => 'written_off'])
            ->assertSessionHasErrors('resolution_reason');
        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/items/{$item->id}/resolve", [
            'resolution' => 'written_off', 'resolution_reason' => 'سُرق منه',
        ])->assertSessionHasNoErrors();
        $this->actingAs($accountant)->post($this->host()."/profits/batches/{$batch->id}/approve")->assertSessionHasNoErrors();

        $this->assertSame(2_000, $this->balance(A::WRITE_OFFS));
        $this->assertSame(0, $this->balance(A::SHORTAGES));
        // المشطوب لا يبقى على المندوب
        $this->assertSame($inHand, $this->cashInHand($this->ahmed));
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => $item->refresh()->outstanding()));
        $this->assertJournalOk();
    }

    public function test_the_owner_may_approve_an_unresolved_shortage_by_documented_exception_as_courier_debt(): void
    {
        $batch = $this->batch([$this->settled($this->ahmed, [40_000])]);
        $item = $this->item($batch, $this->ahmed);
        $this->received($batch, $item, (int) $item->expected_cash - 1_000);

        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/approve", ['exception' => 'يُراجع غداً'])
            ->assertSessionHasNoErrors();

        $item = $this->item($batch, $this->ahmed);
        $this->assertSame('courier_debt', $item->resolution);
        $this->assertSame(1_000, $this->balance(A::SHORTAGES, ['courier_id' => $this->ahmed->id]));
        $this->assertTrue(Tenancy::runFor($this->company, fn () => $batch->audits()->where('action', 'approved_exception')->exists()));
    }

    public function test_an_excess_is_held_in_the_box_as_a_liability(): void
    {
        $batch = $this->batch([$this->settled($this->ahmed, [40_000])]);
        $item = $this->item($batch, $this->ahmed);
        $box = $this->boxBalance();

        $this->received($batch, $item, (int) $item->expected_cash + 3_000);
        $this->assertSame('over', $this->item($batch, $this->ahmed)->status);
        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/approve")->assertSessionHasNoErrors();

        $this->assertSame($box + 3_000, $this->boxBalance());
        $this->assertSame(-3_000, $this->balance(A::EXCESS_CASH));
        $this->assertJournalOk();
    }

    public function test_a_correction_after_approval_posts_only_the_difference_with_its_reason(): void
    {
        $batch = $this->batch([$this->settled($this->ahmed, [40_000])]);
        $item = $this->item($batch, $this->ahmed);
        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/approve")->assertSessionHasNoErrors();
        $box = $this->boxBalance();
        $expected = (int) $item->expected_cash;

        $this->received($batch, $item, $expected - 3_000)->assertSessionHasErrors('reason');
        $this->received($batch, $item, $expected - 3_000, 'أُعيد العدّ')->assertSessionHasNoErrors();
        $this->assertSame($box - 3_000, $this->boxBalance());
        $this->assertSame(3_000, $this->balance(A::SHORTAGES, ['courier_id' => $this->ahmed->id]));

        // ومن عجزٍ إلى زيادة: يُردّ العجز كلّه وتُعلَّق الزيادة
        $this->received($batch, $item, $expected + 1_000, 'وُجد في الدرج')->assertSessionHasNoErrors();
        $this->assertSame($box + 1_000, $this->boxBalance());
        $this->assertSame(0, $this->balance(A::SHORTAGES));
        $this->assertSame(-1_000, $this->balance(A::EXCESS_CASH));
        $this->assertSame(2, Tenancy::runFor($this->company, fn () => $batch->audits()->where('action', 'corrected')->count()));
        $this->assertJournalOk();
    }

    public function test_cancelling_keeps_the_record_and_frees_its_settlements_and_couriers(): void
    {
        $settlement = $this->settled($this->ali, [20_000]);
        $this->deliver($this->ahmed, 30_000);
        $batch = $this->batch([$settlement]);
        $courierBatch = Tenancy::runFor($this->company, fn () => app(ManageCollectionBatch::class)
            ->createForCouriers([$this->ahmed->id], null, $this->box->id, null, $this->owner));

        $this->actingAs($this->owner)->delete($this->host()."/profits/batches/{$batch->id}")->assertSessionHasErrors('reason');
        $this->actingAs($this->owner)->delete($this->host()."/profits/batches/{$batch->id}", ['reason' => 'أُنشئت بالخطأ'])
            ->assertRedirect($this->host()."/profits/batches/{$batch->id}");
        $this->actingAs($this->owner)->delete($this->host()."/profits/batches/{$courierBatch->id}", ['reason' => 'المندوب غائب']);

        $batch = $this->fresh($batch);
        $this->assertSame(['cancelled', 'أُنشئت بالخطأ'], [$batch->status, $batch->cancel_reason]);
        $this->assertTrue(Tenancy::runFor($this->company, fn () => $batch->audits()->where('action', 'cancelled')->exists()));
        $this->actingAs($this->owner)->get($this->host()."/profits/batches/{$batch->id}")->assertOk()->assertSee('أُلغيت هذه الوجبة');

        Tenancy::runFor($this->company, function () use ($settlement) {
            $this->assertTrue(ManageCollectionBatch::eligible($this->owner)->whereKey($settlement->id)->exists());
            $this->assertTrue(ManageCollectionBatch::couriersToSettle($this->owner)->whereKey($this->ahmed->id)->exists());
            // والملغاة لا تُعدَّل
            $this->expectValidation(fn () => app(ManageCollectionBatch::class)->approve(CollectionBatch::where('number', 'COL-0001')->first(), $this->owner), 'ملغاة');
        });
    }

    public function test_dual_approval_keeps_the_creator_from_approving_their_own_batch(): void
    {
        Tenancy::runFor($this->company, fn () => $this->company->forceFill(['settings' => ['finance' => ['dual_approval' => true]]])->save());
        $admin = $this->makeUser($this->company, UserRole::CompanyAdmin);
        $batch = $this->batch([$this->settled($this->ahmed, [40_000])]);

        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/approve")
            ->assertSessionHasErrors(['batch' => 'يعتمد الوجبة غيرُ من أنشأها (إعداد الشركة).']);
        $this->actingAs($admin)->post($this->host()."/profits/batches/{$batch->id}/approve")->assertSessionHasNoErrors();

        $this->assertSame((int) $admin->id, (int) $this->fresh($batch)->approved_by_user_id);
    }

    public function test_dual_approval_is_a_company_setting(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/finance/settings')->assertOk()->assertSee('من أنشأ وجبة التحصيل لا يعتمدها');
    }

    public function test_attachments_are_uploaded_and_downloaded_from_the_batch(): void
    {
        Storage::fake('local');
        $batch = $this->batch([$this->settled($this->ahmed, [40_000])]);

        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/files", [
            'file' => UploadedFile::fake()->create('ورقة-العد.pdf', 120, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/files", [
            'file' => UploadedFile::fake()->create('script.php', 1, 'text/x-php'),
        ])->assertSessionHasErrors('file');

        $file = Tenancy::runFor($this->company, fn () => $batch->files()->sole());
        Storage::disk('local')->assertExists($file->path);

        $this->actingAs($this->owner)->get($this->host()."/profits/batches/{$batch->id}")->assertOk()->assertSee('ورقة-العد.pdf');
        $this->actingAs($this->owner)->get($this->host()."/profits/batches/{$batch->id}/files/{$file->id}")->assertOk()->assertDownload();
        $this->assertTrue(Tenancy::runFor($this->company, fn () => $batch->audits()->where('action', 'file_added')->exists()));
    }

    public function test_the_batch_log_exports_to_excel_with_its_filters(): void
    {
        $this->batch([$this->settled($this->ahmed, [40_000])]);

        $this->actingAs($this->owner)->get($this->host().'/profits/batches')->assertOk()->assertSee('تصدير Excel');
        $this->actingAs($this->owner)->get($this->host().'/profits/batches/export?status=open')
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_the_courier_statement_shows_what_is_unsettled_and_offers_a_batch(): void
    {
        $this->deliver($this->ahmed, 25_000);

        $this->actingAs($this->owner)->get($this->host()."/profits/couriers/{$this->ahmed->id}")
            ->assertOk()->assertSee('لم يُحاسَب عليه بعد')->assertSee('25,000')->assertSee('وجبة تحصيل لهذا المندوب');
        $this->actingAs($this->owner)->get($this->host()."/couriers/{$this->ahmed->id}")->assertOk()->assertSee('كشفه المالي');

        $service = $this->makeUser($this->company, UserRole::CustomerService);
        $this->actingAs($service)->get($this->host()."/profits/couriers/{$this->ahmed->id}")->assertForbidden();
    }

    private function expectValidation(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail('توقّعت رفضاً.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($needle, implode(' ', $e->validator->errors()->all()));
        }
    }
}
