<?php

namespace Tests\Feature\Platform;

use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Finance\Journal;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * حذف تاجرٍ واحدٍ وكلّ ما له من لوحة النواة (docs/plan/108): تاجرٌ تجريبيّ يُحذف بشحناته وطلباته ورسائله
 * وحركات حسابه وقيودها، ولا يُمسّ تاجرٌ غيره ولا مالُه، ويسلم الدفتر وأرصدة المندوب. ومن اختلط ماله بمال غيره
 * (كشفٌ، سلفة…) لا يُحذف وحده.
 */
class MerchantPurgeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private User $owner;

    private Courier $courier;

    private Merchant $test;

    private Merchant $real;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->admin = Tenancy::runAsPlatform(fn () => User::create([
            'name' => 'مدير المنصّة', 'phone' => '07700000000',
            'password' => 'secret-pass', 'role' => UserRole::PlatformAdmin, 'is_active' => true,
        ]));
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->owner = $this->makeUser($this->company);
        $this->test = $this->makeMerchant($this->company, 'M0009');
        $this->real = $this->makeMerchant($this->company, 'M0001');
        Tenancy::runFor($this->company, function () {
            $this->test->forceFill(['business_name' => 'test'])->save();
            $this->real->forceFill(['business_name' => 'متجر حقيقي'])->save();
            $this->courier = Courier::create(['code' => 'C1', 'name' => 'مندوب', 'phone' => '07721112222', 'type' => 'delivery', 'status' => 'active']);
        });

        // لكلٍّ منهما شحنةٌ سُلّمت مع المندوب نفسه وأخرى جديدة؛ وللتجريبيّ حساب دخول ورسالة وطلب استلام
        foreach ([$this->test, $this->real] as $merchant) {
            $this->delivered($merchant);
            $this->shipment($merchant);
        }
        Tenancy::runFor($this->company, function () {
            User::create(['name' => 'test', 'phone' => '07790000009', 'password' => 'password', 'role' => UserRole::Merchant,
                'merchant_id' => $this->test->id, 'is_active' => true]);
            $conversation = DB::table('conversations')->insertGetId(['company_id' => $this->company->id, 'merchant_id' => $this->test->id,
                'subject' => 'تجربة', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conversation_messages')->insert(['company_id' => $this->company->id, 'conversation_id' => $conversation,
                'author' => 'merchant', 'body' => 'تجربة', 'created_at' => now()]);
            DB::table('pickup_requests')->insert(['company_id' => $this->company->id, 'merchant_id' => $this->test->id, 'number' => 'P9',
                'status' => 'pending', 'expected_count' => 1, 'actual_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    private function shipment(Merchant $merchant): int
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $merchant->id, 'recipient_name' => 'زبون '.$merchant->code, 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 50_000,
        ], $this->owner)->id);
    }

    private function delivered(Merchant $merchant): int
    {
        $id = $this->shipment($merchant);
        Tenancy::runFor($this->company, function () use ($id) {
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle(Shipment::findOrFail($id), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null]);
            }
        });

        return $id;
    }

    private function confirm(array $extra = []): array
    {
        return $extra + ['company' => $this->company->id, 'merchant' => 'test', 'confirm_code' => 'M0009',
            'password' => 'secret-pass', 'understood' => 1];
    }

    private function rowsOf(string $table, ?int $merchantId = null): int
    {
        return DB::table($table)->where('company_id', $this->company->id)
            ->when($merchantId, fn ($q) => $q->where('merchant_id', $merchantId))->count();
    }

    public function test_the_test_merchant_goes_with_everything_it_had_and_nothing_else_moves(): void
    {
        $realShipments = DB::table('shipments')->where('merchant_id', $this->real->id)->pluck('id');
        $realTx = DB::table('transactions')->whereIn('shipment_id', $realShipments)->count();
        $this->assertGreaterThan(0, DB::table('transactions')->whereIn('shipment_id', DB::table('shipments')->where('merchant_id', $this->test->id)->pluck('id'))->count());

        // يُعرض أوّلاً بأعداده، ولا يُحذف شيء
        $this->actingAs($this->admin)->get('/admin/purge')->assertOk()->assertSee('حذف تاجرٍ واحدٍ وكلّ ما له');
        $this->get('/admin/purge/merchant?'.http_build_query(['company' => $this->company->id, 'merchant' => 'TEST']))
            ->assertOk()->assertSee('حذف التاجر test من الزاجل')->assertSee('M0009')->assertSee('احذف التاجر نهائياً');
        $this->assertSame(2, $this->rowsOf('shipments', $this->test->id));

        // رمزٌ خاطئ، وكلمة مرورٍ خاطئة: لا شيء
        $this->post('/admin/purge/merchant', $this->confirm(['confirm_code' => 'M0001']))->assertSessionHasErrors('confirm_code');
        $this->post('/admin/purge/merchant', $this->confirm(['password' => 'wrong']))->assertSessionHasErrors('password');
        $this->assertSame(2, $this->rowsOf('shipments', $this->test->id));

        $this->post('/admin/purge/merchant', $this->confirm())
            ->assertRedirect('/admin/purge?company='.$this->company->id)
            ->assertSessionHas('purged.counts.shipments', 2)->assertSessionHas('purged.counts.merchants', 1);

        // التجريبيّ وكلّ ما له
        $this->assertNull(DB::table('merchants')->find($this->test->id));
        $this->assertSame(0, $this->rowsOf('shipments', $this->test->id));
        $this->assertSame(0, DB::table('users')->where('merchant_id', $this->test->id)->count());
        $this->assertSame(0, $this->rowsOf('conversations', $this->test->id));
        $this->assertSame(0, $this->rowsOf('pickup_requests', $this->test->id));
        $this->assertSame(0, DB::table('transactions')->where('account_type', 'merchant')->where('account_id', $this->test->id)->count());
        $this->assertSame(0, DB::table('journal_lines')->where('merchant_id', $this->test->id)->count());

        // الحقيقيّ كما هو، ورصيد المندوب من شحنته وحدها، والدفتر سليم
        $this->assertSame(2, $this->rowsOf('shipments', $this->real->id));
        $this->assertSame($realTx, DB::table('transactions')->whereIn('shipment_id', $realShipments)->count());
        $courierCash = DB::table('transactions')->where('account_type', 'courier')->where('account_id', $this->courier->id)
            ->whereNotIn('category', ['commission', 'commission_paid'])->get()
            ->sum(fn ($t) => $t->direction === 'credit' ? -$t->amount : $t->amount);
        $this->assertSame((int) $courierCash, (int) DB::table('couriers')->where('id', $this->courier->id)->value('cash_in_hand'));
        $this->assertNull(app(Journal::class)->brokenAt($this->company->id));

        $log = AuditLog::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('action', 'merchant_purged')->sole();
        $this->assertSame('M0009', $log->old_values['code']);
        $this->assertSame(2, $log->new_values['counts']['shipments']);
    }

    public function test_a_merchant_whose_money_is_mixed_with_others_is_not_deleted_alone(): void
    {
        // كشف تاجر صدر له: لا يُحذف وحده، ولا يُعرض زرّ الحذف
        $this->settleCourierCash($this->company);
        Tenancy::runFor($this->company, fn () => app(BuildMerchantSettlement::class)->handle($this->test->refresh(), $this->owner));

        $this->actingAs($this->admin)->get('/admin/purge/merchant?'.http_build_query(['company' => $this->company->id, 'merchant' => 'M0009']))
            ->assertOk()->assertSee('لا يُحذف وحده')->assertSee('كشف تاجر')->assertDontSee('احذف التاجر نهائياً');

        $this->post('/admin/purge/merchant', $this->confirm())->assertSessionHasErrors('merchant');
        $this->assertNotNull(DB::table('merchants')->find($this->test->id));
        $this->assertSame(2, $this->rowsOf('shipments', $this->test->id));
    }

    public function test_an_unknown_or_ambiguous_name_is_refused_and_only_the_platform_admin_deletes(): void
    {
        $query = fn (string $m) => '/admin/purge/merchant?'.http_build_query(['company' => $this->company->id, 'merchant' => $m]);

        $this->actingAs($this->admin)->get($query('nobody'))->assertSessionHasErrors('merchant');

        // اسمٌ لتاجرين: يُطلب الرمز
        Tenancy::runFor($this->company, fn () => $this->real->forceFill(['business_name' => 'test'])->save());
        $this->get($query('test'))->assertSessionHasErrors('merchant');
        $this->get($query('M0009'))->assertOk();

        $support = Tenancy::runAsPlatform(fn () => User::create(['name' => 'دعم', 'phone' => '07700000009',
            'password' => 'secret-pass', 'role' => UserRole::PlatformSupport, 'is_active' => true]));
        $this->actingAs($support)->get($query('M0009'))->assertForbidden();
        $this->actingAs($support)->post('/admin/purge/merchant', $this->confirm(['merchant' => 'M0009']))->assertForbidden();
        $this->assertNotNull(DB::table('merchants')->find($this->test->id));
    }
}
