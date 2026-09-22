<?php

namespace Tests\Feature\Control;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\DuplicateDetector;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ثلاث لمسات رقابية من النظام المرجعي: كشف المكرّر، والتسليم القسريّ
 * بسبب مكتوب، وإخفاء أرقام الزبائن.
 */
class ControlTouchesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $alpha;

    private Merchant $beta;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->alpha = $this->makeMerchant($this->company, 'M0001');
        $this->beta = $this->makeMerchant($this->company, 'M0002');
        $this->staff = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function make(array $overrides = []): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle(array_merge([
            'merchant_id'     => $this->alpha->id,
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد', 'landmark' => 'قرب الجامع',
            'cod_amount'      => 50_000,
        ], $overrides), $this->staff));
    }

    // ── كشف المكرّر ─────────────────────────────────────────────────

    public function test_the_same_customer_amount_and_merchant_is_flagged(): void
    {
        $first = $this->make();
        $second = $this->make();

        Tenancy::runFor($this->company, function () use ($first, $second) {
            $this->assertNull($first->refresh()->duplicate_of_id, 'الأولى ليست تكراراً لشيء');
            $this->assertSame($first->id, (int) $second->refresh()->duplicate_of_id);
        });
    }

    public function test_flagging_does_not_block_creation(): void
    {
        $this->make();
        $second = $this->make();

        // منعُ الإنشاء يوقف تاجراً له طلبان حقيقيان؛ الوسم يترك القرار للموظّف
        $this->assertNotNull($second->number);
        Tenancy::runFor($this->company, fn () => $this->assertSame(2, Shipment::count()));
    }

    public function test_a_different_amount_is_not_a_duplicate(): void
    {
        $this->make();
        $second = $this->make(['cod_amount' => 60_000]);

        Tenancy::runFor($this->company, fn () => $this->assertNull($second->refresh()->duplicate_of_id));
    }

    public function test_the_same_customer_at_another_merchant_is_not_a_duplicate(): void
    {
        $this->make();
        $second = $this->make(['merchant_id' => $this->beta->id]);

        Tenancy::runFor($this->company, fn () => $this->assertNull($second->refresh()->duplicate_of_id));
    }

    public function test_a_phone_written_differently_is_still_the_same_customer(): void
    {
        $first = $this->make(['recipient_phone' => '07801234567']);
        $second = $this->make(['recipient_phone' => '0780-123-4567']);

        Tenancy::runFor($this->company, fn () => $this->assertSame(
            $first->id, (int) $second->refresh()->duplicate_of_id,
        ));
    }

    public function test_an_old_order_is_outside_the_suspicion_window(): void
    {
        $old = $this->make();

        Tenancy::runFor($this->company, fn () => $old->forceFill([
            'created_at' => now()->subDays(DuplicateDetector::WINDOW_DAYS + 1),
        ])->save());

        $second = $this->make();

        Tenancy::runFor($this->company, fn () => $this->assertNull($second->refresh()->duplicate_of_id));
    }

    public function test_staff_clear_a_suspicion_that_was_two_real_orders(): void
    {
        $this->make();
        $second = $this->make();

        $this->actingAs($this->staff)
            ->post($this->host()."/control/duplicates/{$second->id}/clear")
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($second) {
            $this->assertNotNull($second->refresh()->duplicate_cleared_at);
            $this->assertSame(ShipmentStatus::Created, $second->status, 'رفع الاشتباه لا يُلغي شيئاً');
            $this->assertCount(0, app(DuplicateDetector::class)->pending());
        });
    }

    public function test_staff_cancel_a_real_duplicate_without_deleting_it(): void
    {
        $first = $this->make();
        $second = $this->make();

        $this->actingAs($this->staff)
            ->post($this->host()."/control/duplicates/{$second->id}/cancel")
            ->assertRedirect();

        Tenancy::runFor($this->company, function () use ($first, $second) {
            $this->assertSame(ShipmentStatus::Cancelled, $second->refresh()->status);
            $this->assertSame(ShipmentStatus::Created, $first->refresh()->status);
            $this->assertDatabaseHas('shipment_events', ['shipment_id' => $second->id, 'to_status' => 'cancelled']);
        });
    }

    public function test_the_suspicion_screen_shows_both_sides(): void
    {
        $first = $this->make();
        $second = $this->make();

        $this->actingAs($this->staff)
            ->get($this->host().'/control/duplicates')
            ->assertOk()
            ->assertSee($first->number)
            ->assertSee($second->number);
    }

    // ── واصل إجباري ─────────────────────────────────────────────────

    public function test_an_illegal_jump_needs_a_written_reason(): void
    {
        $shipment = $this->make();

        $this->actingAs($this->staff)
            ->post($this->host()."/shipments/{$shipment->id}/status", [
                'status' => 'delivered', 'force' => 1,
            ])
            ->assertSessionHasErrors('forced_reason');

        Tenancy::runFor($this->company, fn () => $this->assertSame(
            ShipmentStatus::Created, $shipment->refresh()->status,
        ));
    }

    public function test_a_forced_jump_is_recorded_with_its_reason_and_author(): void
    {
        $shipment = $this->make();

        $this->actingAs($this->staff)
            ->post($this->host()."/shipments/{$shipment->id}/status", [
                'status'        => 'delivered',
                'force'         => 1,
                'forced_reason' => 'اتصل الزبون وأكّد الاستلام والمندوب نسي التسجيل',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($shipment) {
            $fresh = $shipment->refresh();

            $this->assertSame(ShipmentStatus::Delivered, $fresh->status);
            $this->assertTrue($fresh->is_forced);
            $this->assertStringContainsString('نسي التسجيل', $fresh->forced_reason);
            $this->assertSame($this->staff->id, $fresh->forced_by_user_id);

            $this->assertDatabaseHas('shipment_events', [
                'shipment_id' => $fresh->id,
                'event_type'  => 'forced_status',
            ]);
        });
    }

    public function test_a_terminal_shipment_is_not_reopened_even_by_force(): void
    {
        $shipment = $this->make();

        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)
            ->handle($shipment, ShipmentStatus::Cancelled, $this->staff));

        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)
            ->handle($shipment->refresh(), ShipmentStatus::Delivered, $this->staff, [
                'force' => true, 'forced_reason' => 'محاولة',
            ]));
    }

    public function test_the_forced_report_names_who_ordered_each_one(): void
    {
        $shipment = $this->make();

        $this->actingAs($this->staff)
            ->post($this->host()."/shipments/{$shipment->id}/status", [
                'status' => 'delivered', 'force' => 1, 'forced_reason' => 'سبب مكتوب',
            ]);

        $this->actingAs($this->staff)
            ->get($this->host().'/control/forced')
            ->assertOk()
            ->assertSee($shipment->number)
            ->assertSee('سبب مكتوب')
            ->assertSee($this->staff->name);
    }

    public function test_a_normal_transition_is_not_marked_forced(): void
    {
        $shipment = $this->make();

        $this->actingAs($this->staff)
            ->post($this->host()."/shipments/{$shipment->id}/status", ['status' => 'picked_up']);

        Tenancy::runFor($this->company, fn () => $this->assertFalse($shipment->refresh()->is_forced));
    }

    // ── إخفاء أرقام الزبائن ─────────────────────────────────────────

    public function test_a_customer_phone_is_masked_on_the_list(): void
    {
        $this->make(['recipient_phone' => '07801234567']);

        $html = $this->actingAs($this->staff)
            ->get($this->host().'/shipments')
            ->assertOk()
            ->getContent();

        // الرقم في الصفحة للكشف عند الطلب، لكنه ليس معروضاً نصّاً
        $this->assertStringContainsString('••••••••567', $html);
        $this->assertStringContainsString('data-masked="••••••••567"', $html);
        $this->assertStringNotContainsString('>07801234567<', $html);
    }

    public function test_a_merchant_login_cannot_reach_the_control_screens(): void
    {
        $user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->alpha->id, 'is_active' => true,
        ]));

        $this->actingAs($user)->get($this->host().'/control/duplicates')->assertForbidden();
        $this->actingAs($user)->get($this->host().'/control/forced')->assertForbidden();
    }
}
