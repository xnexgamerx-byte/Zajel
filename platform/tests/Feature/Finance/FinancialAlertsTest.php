<?php

namespace Tests\Feature\Finance;

use App\Actions\Profits\ManageCollectionBatch;
use App\Actions\Profits\RecordProfitWithdrawal;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CashBox;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FinancialAlert;
use App\Models\FinancialPeriod;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\CashBook;
use App\Services\Finance\FinancialAlerts;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * التنبيهات المالية (docs/plan/68 §١٢، المرحلة السادسة). المقياس: لكل سببٍ تنبيهٌ واحد بمفتاحه،
 * يُصعَّد حين يشتدّ، ويُغلق وحده حين يزول، ويعود إن عولج وسببه قائم. والحدث يبقى حتى يُعالَج،
 * ومحاولة السحب فوق المتاح تُكتب في لحظتها ولو رُفض الطلب.
 */
class FinancialAlertsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    private CashBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->courier, $this->box] = Tenancy::runFor($this->company, fn () => [
            Courier::create(['code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500]),
            CashBox::create(['code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 0, 'is_active' => true]),
        ]);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function deliver(int $cod): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
            ], $this->owner);

            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null,
                ]);
            }

            return $shipment->refresh();
        });
    }

    private function scan(?array $types = null): array
    {
        return Tenancy::runFor($this->company, fn () => app(FinancialAlerts::class)->scan($types));
    }

    private function alert(string $key): ?FinancialAlert
    {
        return Tenancy::runFor($this->company, fn () => FinancialAlert::with('actions')->where('key', $key)->first());
    }

    public function test_late_courier_cash_opens_once_escalates_and_clears_when_handed_over(): void
    {
        $this->travelTo(now()->subHours(30));
        $this->deliver(50_000);
        $this->travelBack();

        $this->scan();
        $this->scan();
        $alert = $this->alert('courier_cash_late:'.$this->courier->id);

        $this->assertSame('warning', $alert->severity);
        $this->assertSame('open', $alert->status);
        $this->assertSame(50_000, (int) $alert->amount);
        $this->assertSame('batches.prepare', $alert->assignee_ability);
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => FinancialAlert::where('type', 'courier_cash_late')->count()));
        $this->assertNull($this->alert('old_custody:'.$this->courier->id), 'أقلّ من حدّ العهدة القديمة.');

        // ضعف المهلة: حرج
        $this->travelTo(now()->addHours(20));
        $this->scan();
        $alert = $this->alert('courier_cash_late:'.$this->courier->id);
        $this->assertSame('critical', $alert->severity);
        $this->assertContains('escalated', $alert->actions->pluck('action')->all());

        // سلّم نقده: زال السبب فيُغلق وحده
        Tenancy::runFor($this->company, fn () => app(ConfirmCourierSettlement::class)->handle(
            app(BuildCourierSettlement::class)->handle($this->courier->refresh(), $this->owner), $this->owner, box: $this->box));
        $this->scan();
        $alert = $this->alert('courier_cash_late:'.$this->courier->id);
        $this->assertSame('resolved', $alert->status);
        $this->assertNotNull($alert->cleared_at);
        $this->assertNull($alert->resolved_by_user_id);
        $this->assertSame('cleared', $alert->actions->last()->action);
        $this->travelBack();
    }

    public function test_a_resolved_alert_reopens_while_its_cause_remains_and_an_ignored_one_waits_until_it_returns(): void
    {
        $this->travelTo(now()->subHours(30));
        $this->deliver(50_000);
        $this->travelBack();
        $this->scan();
        $key = 'courier_cash_late:'.$this->courier->id;
        $alerts = app(FinancialAlerts::class);

        Tenancy::runFor($this->company, fn () => $alerts->resolve($this->alert($key), $this->owner, 'اتّصلت به'));
        $this->assertSame('resolved', $this->alert($key)->status);
        $this->assertTrue(Tenancy::runFor($this->company, fn () => AuditLog::where('action', 'financial_alert')->exists()));

        $this->scan();
        $this->assertSame('open', $this->alert($key)->status, 'عولج وسببه قائم: يُفتح ثانيةً.');
        $this->assertSame('reopened', $this->alert($key)->actions->last()->action);

        try {
            Tenancy::runFor($this->company, fn () => $alerts->ignore($this->alert($key), $this->owner, ' '));
            $this->fail('التجاهل بلا سبب.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        Tenancy::runFor($this->company, fn () => $alerts->ignore($this->alert($key), $this->owner, 'المندوب في إجازة ونقده مضمون'));
        $this->scan();
        $this->assertSame('ignored', $this->alert($key)->status, 'المتجاهَل لا يعود ما دام سببه هو هو.');
    }

    public function test_an_over_available_withdrawal_raises_an_immediate_critical_alert_though_refused(): void
    {
        Tenancy::runFor($this->company, function () {
            foreach (['request', 'handle'] as $method) {
                try {
                    app(RecordProfitWithdrawal::class)->{$method}(1_000_000, now(), $this->box, 'الشريك', 'سحب', $this->owner);
                    $this->fail('سحبٌ فوق المتاح.');
                } catch (ValidationException) {
                }
                $this->travel(2)->seconds();
            }
        });

        $alerts = Tenancy::runFor($this->company, fn () => FinancialAlert::where('type', 'withdrawal_over')->get());
        $this->assertCount(2, $alerts);
        $this->assertSame('critical', $alerts->first()->severity);
        $this->assertSame(1_000_000, (int) $alerts->first()->amount);

        // حدثٌ لا يُغلقه الفحص
        $this->scan();
        $this->assertSame(2, Tenancy::runFor($this->company, fn () => FinancialAlert::where('type', 'withdrawal_over')->where('status', 'open')->count()));
    }

    public function test_a_short_count_raises_a_difference_event_and_a_discrepancy_state_until_approved(): void
    {
        $this->deliver(50_000);

        $batch = Tenancy::runFor($this->company, function () {
            $manage = app(ManageCollectionBatch::class);
            $batch = $manage->createForCouriers([$this->courier->id], null, $this->box->id, null, $this->owner);
            $item = $batch->items()->first();

            return $manage->receive($batch, $item, (int) $item->expected_cash - 5_000, null, $this->owner);
        });
        $this->assertSame('discrepancy', $batch->status);

        $this->scan();
        $item = Tenancy::runFor($this->company, fn () => $batch->items()->first());
        $difference = $this->alert('count_difference:'.$item->id);
        $this->assertSame('critical', $difference->severity);
        $this->assertSame(-5_000, (int) $difference->amount);
        $this->assertSame('open', $this->alert('batch_discrepancy:'.$batch->id)->status);

        Tenancy::runFor($this->company, function () use ($batch, $item) {
            $manage = app(ManageCollectionBatch::class);
            $manage->resolve($batch, $item, 'courier_debt', 'يسدّده الأسبوع القادم', $this->owner);
            $manage->approve($batch->refresh(), $this->owner);
        });
        $this->scan();

        $this->assertSame('resolved', $this->alert('batch_discrepancy:'.$batch->id)->status);
        $this->assertSame('open', $this->alert('count_difference:'.$item->id)->status, 'الحدث يبقى حتى يُعالَج.');
    }

    public function test_duplicate_box_movements_and_a_pending_period_are_flagged(): void
    {
        Tenancy::runFor($this->company, function () {
            $cash = app(CashBook::class);
            $cash->in($this->box, 'adjustment', 25_000, 'تسوية جرد', $this->owner);
            $cash->in($this->box, 'adjustment', 25_000, 'تسوية جرد', $this->owner);
            $cash->in($this->box, 'adjustment', 10_000, 'تسوية أخرى', $this->owner);

            FinancialPeriod::create(['number' => 'FP-0001', 'from_date' => now()->subMonth()->startOfMonth(), 'to_date' => now()->subMonth()->endOfMonth(),
                'status' => 'pending_approval', 'prepared_by_user_id' => $this->owner->id, 'prepared_at' => now()]);
        });

        $this->scan();
        $duplicates = Tenancy::runFor($this->company, fn () => FinancialAlert::where('type', 'duplicate_movement')->get());
        $this->assertCount(1, $duplicates);
        $this->assertSame(25_000, (int) $duplicates->first()->amount);

        $pending = Tenancy::runFor($this->company, fn () => FinancialAlert::where('type', 'period_pending')->first());
        $this->assertSame('info', $pending->severity);
        $this->assertSame('periods.close', $pending->assignee_ability);

        Tenancy::runFor($this->company, fn () => FinancialPeriod::query()->delete());
        $this->scan();
        $this->assertSame('resolved', Tenancy::runFor($this->company, fn () => FinancialAlert::where('type', 'period_pending')->value('status')));
    }

    public function test_the_finance_tab_lists_alerts_for_profit_viewers_and_records_actions(): void
    {
        $this->travelTo(now()->subHours(30));
        $this->deliver(50_000);
        $this->travelBack();
        $this->scan();
        $alert = $this->alert('courier_cash_late:'.$this->courier->id);

        $this->actingAs($this->owner)->get($this->host().'/operational-alerts?tab=finance')
            ->assertOk()->assertSee('التنبيهات المالية')->assertSee($alert->title);

        $this->actingAs($this->owner)->post($this->host()."/operational-alerts/finance/{$alert->id}/take")->assertRedirect();
        $this->assertSame('in_progress', $this->alert($alert->key)->status);
        $this->assertSame($this->owner->id, (int) $this->alert($alert->key)->assigned_user_id);

        $this->actingAs($this->owner)->post($this->host()."/operational-alerts/finance/{$alert->id}/ignore", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->owner)->post($this->host().'/operational-alerts/finance/scan')->assertRedirect();

        // من لا يرى الأرباح لا يرى التبويب ولا يفعل به شيئاً
        $clerk = $this->makeUser($this->company, UserRole::CustomerService);
        $this->actingAs($clerk)->get($this->host().'/operational-alerts?tab=finance')->assertOk()->assertDontSee($alert->title);
        $this->actingAs($clerk)->post($this->host()."/operational-alerts/finance/{$alert->id}/take")->assertForbidden();

        $this->artisan('finance:alerts')->assertSuccessful();
        $this->artisan('finance:alerts --daily')->assertSuccessful();
    }
}
