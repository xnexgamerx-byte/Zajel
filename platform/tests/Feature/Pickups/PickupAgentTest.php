<?php

namespace Tests\Feature\Pickups;

use App\Actions\Pickups\AccruePickupShare;
use App\Actions\Pickups\PayPickupCommission;
use App\Actions\Shipments\CompletePickup;
use App\Actions\Shipments\CreateShipment;
use App\Enums\UserRole;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRequest;
use App\Models\PickupShare;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * مندوب الاستلام دور محاسبيّ مستقلّ.
 *
 * كان حقل «عمولة الاستلام» يُملأ في شاشة المندوب ولا يكتبه أحد في
 * الدفتر: يجمع الطرود شهراً كاملاً ولا يُستحقّ له دينار. وله فوق ذلك
 * حقّ الاعتراض على احتساب حصّته.
 */
class PickupAgentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $staff;

    private Courier $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->staff = $this->makeUser($this->company);

        $this->agent = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'P1', 'name' => 'سعد الجبوري', 'phone' => '07730000001',
            'type' => 'pickup', 'status' => 'active', 'commission_per_pickup' => 500,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function agentUser(): User
    {
        return Tenancy::runFor($this->company, fn () => User::create([
            'name' => $this->agent->name, 'phone' => '07730000009', 'password' => 'password',
            'role' => UserRole::Courier, 'courier_id' => $this->agent->id, 'is_active' => true,
        ]));
    }

    private function pickup(int $shipments = 4): PickupRequest
    {
        return Tenancy::runFor($this->company, function () use ($shipments) {
            for ($i = 0; $i < $shipments; $i++) {
                app(CreateShipment::class)->handle([
                    'merchant_id'     => $this->merchant->id,
                    'recipient_name'  => 'زبون '.$i,
                    'recipient_phone' => '07801234567',
                    'governorate_id'  => $this->baghdad()->id,
                    'address'         => 'بغداد', 'landmark' => 'قرب الجامع',
                    'cod_amount'      => 50_000,
                ], $this->staff);
            }

            return PickupRequest::create([
                'merchant_id' => $this->merchant->id,
                'number'      => 'PU'.str_pad((string) PickupRequest::count() + 1, 4, '0', STR_PAD_LEFT),
                'courier_id'  => $this->agent->id,
                'status'      => 'assigned',
                'items_count' => $shipments,
            ]);
        });
    }

    private function complete(PickupRequest $pickup, int $count): void
    {
        Tenancy::runFor($this->company, fn () => app(CompletePickup::class)
            ->handle($pickup, $count, $this->staff));
    }

    // ── الاستحقاق ───────────────────────────────────────────────────

    public function test_completing_a_pickup_earns_the_agent_his_share(): void
    {
        $pickup = $this->pickup(4);
        $this->complete($pickup, 4);

        Tenancy::runFor($this->company, function () use ($pickup) {
            $share = PickupShare::where('pickup_request_id', $pickup->id)->firstOrFail();

            $this->assertSame(4, (int) $share->shipments_count);
            $this->assertSame(500, (int) $share->rate);
            $this->assertSame(2_000, (int) $share->amount);
            $this->assertSame('accrued', $share->status);

            // وهو مستحقّ في الدفتر لا رقماً على الورق
            $this->assertSame(2_000, (int) $this->agent->refresh()->commission_balance);
            $this->assertTrue(app(Ledger::class)->reconcile('courier', $this->agent->id)['matches']);
        });
    }

    public function test_the_rate_is_frozen_at_the_moment_it_is_earned(): void
    {
        $pickup = $this->pickup(4);
        $this->complete($pickup, 4);

        // رفع الشركة الحصّة لاحقاً لا يُعيد تسعير عملٍ مضى
        Tenancy::runFor($this->company, function () use ($pickup) {
            $this->agent->forceFill(['commission_per_pickup' => 900])->save();

            $share = PickupShare::where('pickup_request_id', $pickup->id)->firstOrFail();
            $this->assertSame(500, (int) $share->rate);
            $this->assertSame(2_000, (int) $share->amount);
        });
    }

    public function test_a_pickup_closed_twice_does_not_pay_twice(): void
    {
        $pickup = $this->pickup(3);
        $this->complete($pickup, 3);

        Tenancy::runFor($this->company, fn () => app(AccruePickupShare::class)
            ->handle($pickup->refresh(), $this->staff));

        Tenancy::runFor($this->company, function () {
            $this->assertSame(1, PickupShare::count());
            $this->assertSame(1_500, (int) $this->agent->refresh()->commission_balance);
        });
    }

    public function test_an_agent_without_a_rate_earns_nothing_rather_than_zero_rows(): void
    {
        Tenancy::runFor($this->company, fn () => $this->agent
            ->forceFill(['commission_per_pickup' => 0])->save());

        $this->complete($this->pickup(3), 3);

        Tenancy::runFor($this->company, fn () => $this->assertSame(0, PickupShare::count()));
    }

    // ── الاعتراض ────────────────────────────────────────────────────

    public function test_the_agent_objects_from_his_own_screen(): void
    {
        $pickup = $this->pickup(4);
        $this->complete($pickup, 4);

        $share = Tenancy::runFor($this->company, fn () => PickupShare::firstOrFail());

        $this->actingAs($this->agentUser())
            ->post($this->host()."/courier/shares/{$share->id}/object", [
                'claimed_count' => 6,
                'reason'        => 'جمعتُ طردين إضافيين لم يُسجَّلا',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($share) {
            $fresh = $share->refresh();
            $this->assertSame('objected', $fresh->status);
            $this->assertSame(6, (int) $fresh->claimed_count);
            $this->assertNotNull($fresh->objected_at);
        });
    }

    public function test_an_agent_cannot_object_to_another_agents_share(): void
    {
        $pickup = $this->pickup(4);
        $this->complete($pickup, 4);

        $other = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'P2', 'name' => 'آخر', 'phone' => '07730000002',
            'type' => 'pickup', 'status' => 'active', 'commission_per_pickup' => 500,
        ]));

        $otherUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'آخر', 'phone' => '07730000008', 'password' => 'password',
            'role' => UserRole::Courier, 'courier_id' => $other->id, 'is_active' => true,
        ]));

        $share = Tenancy::runFor($this->company, fn () => PickupShare::firstOrFail());

        $this->actingAs($otherUser)
            ->post($this->host()."/courier/shares/{$share->id}/object", [
                'claimed_count' => 99, 'reason' => 'محاولة',
            ])
            ->assertNotFound();

        Tenancy::runFor($this->company, fn () => $this->assertSame('accrued', $share->refresh()->status));
    }

    public function test_accepting_an_objection_posts_the_difference_not_a_rewrite(): void
    {
        $pickup = $this->pickup(4);
        $this->complete($pickup, 4);

        $share = Tenancy::runFor($this->company, fn () => PickupShare::firstOrFail());

        Tenancy::runFor($this->company, fn () => app(AccruePickupShare::class)
            ->object($share, 6, 'طردان لم يُسجَّلا'));

        $this->actingAs($this->staff)
            ->post($this->host()."/pickup-agents/objections/{$share->id}", [
                'decision' => 'accept', 'agreed_count' => 6, 'note' => 'راجعنا وصولات الطلب',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($share) {
            $fresh = $share->refresh();

            $this->assertSame('adjusted', $fresh->status);
            $this->assertSame(1_000, (int) $fresh->adjustment);
            $this->assertSame(3_000, $fresh->finalAmount());
            $this->assertSame(3_000, (int) $this->agent->refresh()->commission_balance);

            // الحركة الأصلية باقية والفرق حركة مستقلّة
            $rows = Transaction::where('reference_type', 'pickup_share')->orderBy('id')->get();
            $this->assertCount(2, $rows);
            $this->assertSame([2_000, 1_000], $rows->pluck('amount')->map(fn ($a) => (int) $a)->all());
            $this->assertTrue(app(Ledger::class)->reconcile('courier', $this->agent->id)['matches']);
        });
    }

    public function test_rejecting_an_objection_changes_no_money(): void
    {
        $pickup = $this->pickup(4);
        $this->complete($pickup, 4);

        $share = Tenancy::runFor($this->company, fn () => PickupShare::firstOrFail());
        Tenancy::runFor($this->company, fn () => app(AccruePickupShare::class)->object($share, 9, 'مبالغة'));

        $this->actingAs($this->staff)
            ->post($this->host()."/pickup-agents/objections/{$share->id}", [
                'decision' => 'reject', 'note' => 'الوصولات أربعة لا أكثر',
            ]);

        Tenancy::runFor($this->company, function () use ($share) {
            $this->assertSame('rejected', $share->refresh()->status);
            $this->assertSame(0, (int) $share->adjustment);
            $this->assertSame(2_000, (int) $this->agent->refresh()->commission_balance);
        });
    }

    public function test_an_objection_cannot_be_resolved_twice(): void
    {
        $pickup = $this->pickup(4);
        $this->complete($pickup, 4);

        $share = Tenancy::runFor($this->company, fn () => PickupShare::firstOrFail());
        Tenancy::runFor($this->company, fn () => app(AccruePickupShare::class)->object($share, 6, 'سبب'));
        Tenancy::runFor($this->company, fn () => app(AccruePickupShare::class)
            ->resolve($share->refresh(), 6, 'قُبل', $this->staff));

        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, fn () => app(AccruePickupShare::class)
            ->resolve($share->refresh(), 9, 'مرّة أخرى', $this->staff));
    }

    // ── الدفع ───────────────────────────────────────────────────────

    public function test_paying_the_agent_empties_his_balance_and_the_drawer(): void
    {
        $this->complete($this->pickup(4), 4);

        $box = Tenancy::runFor($this->company, function () {
            $box = CashBox::create(['code' => 'MAIN', 'name' => 'القاصة', 'type' => 'main',
                                    'balance' => 0, 'is_active' => true]);
            app(\App\Services\CashBook::class)->in($box, 'opening', 500_000, null, $this->staff);

            return $box;
        });

        $this->actingAs($this->staff)
            ->post($this->host()."/pickup-agents/{$this->agent->id}/pay", ['cash_box_id' => $box->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($box) {
            $this->assertSame(0, (int) $this->agent->refresh()->commission_balance);
            $this->assertSame(498_000, (int) $box->refresh()->balance);
            $this->assertSame(1, CashMovement::where('category', 'commission_paid')->count());
            $this->assertTrue(app(Ledger::class)->reconcile('courier', $this->agent->id)['matches']);
        });
    }

    public function test_an_open_objection_blocks_payment(): void
    {
        $this->complete($this->pickup(4), 4);

        $share = Tenancy::runFor($this->company, fn () => PickupShare::firstOrFail());
        Tenancy::runFor($this->company, fn () => app(AccruePickupShare::class)->object($share, 6, 'سبب'));

        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, fn () => app(PayPickupCommission::class)
            ->handle($this->agent->refresh(), $this->staff));
    }

    public function test_paying_nothing_is_refused_rather_than_writing_an_empty_row(): void
    {
        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, fn () => app(PayPickupCommission::class)
            ->handle($this->agent, $this->staff));
    }

    // ── الشاشات ─────────────────────────────────────────────────────

    public function test_the_account_screen_shows_what_he_earned(): void
    {
        $this->complete($this->pickup(4), 4);

        $this->actingAs($this->staff)
            ->get($this->host().'/pickup-agents')
            ->assertOk()
            ->assertSee('سعد الجبوري')
            ->assertSee('2,000');
    }

    public function test_a_merchant_login_cannot_reach_the_agent_accounts(): void
    {
        $user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));

        $this->actingAs($user)->get($this->host().'/pickup-agents')->assertForbidden();
        $this->actingAs($user)->get($this->host().'/pickup-agents/objections')->assertForbidden();
    }
}
