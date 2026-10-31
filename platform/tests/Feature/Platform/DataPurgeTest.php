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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * حذف بيانات شركةٍ من لوحة النواة (docs/plan/102): لا يُحذف شيءٌ قبل التأكيد باسمها المختصر وكلمة المرور،
 * ويُحذف ما اختير في مدّته من هذه الشركة وحدها، ويبقى صاحبها، ويسلم الدفتر وأرصدته.
 */
class DataPurgeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->admin = Tenancy::runAsPlatform(fn () => User::create([
            'name' => 'مدير المنصّة', 'phone' => '07700000000',
            'password' => 'secret-pass', 'role' => UserRole::PlatformAdmin, 'is_active' => true,
        ]));
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->other = $this->makeCompany('other', 'شركة أخرى');
        $this->fill($this->company);
        $this->fill($this->other);
    }

    /** شركةٌ بعملها: تاجرٌ وحسابه، ومندوبٌ وحسابه، وموظّف، وشحنتان سُلّمت إحداهما وكُشفت، ورسالة، وطلب استلام */
    private function fill(Company $company): void
    {
        $merchant = $this->makeMerchant($company);
        $owner = $this->makeUser($company);
        $this->makeUser($company, UserRole::Operations);

        Tenancy::runFor($company, function () use ($company, $merchant, $owner) {
            $courier = Courier::create(['code' => 'C1', 'name' => 'مندوب', 'phone' => $this->phoneFrom($company->slug.'c', '0772'),
                'type' => 'delivery', 'status' => 'active']);
            User::create(['name' => 'مندوب', 'phone' => $courier->phone, 'password' => 'password', 'role' => UserRole::Courier,
                'courier_id' => $courier->id, 'is_active' => true]);
            User::create(['name' => 'تاجر', 'phone' => $this->phoneFrom($company->slug.'m', '0779'), 'password' => 'password',
                'role' => UserRole::Merchant, 'merchant_id' => $merchant->id, 'is_active' => true]);

            $make = fn () => app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 50_000,
            ], $owner);

            $delivered = $make();
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($delivered->refresh(), $status, $owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier->id : null]);
            }
            $make();

            $this->settleCourierCash($company);
            app(BuildMerchantSettlement::class)->handle($merchant->refresh(), $owner);

            $conversation = DB::table('conversations')->insertGetId(['company_id' => $company->id, 'merchant_id' => $merchant->id,
                'subject' => 'سؤال', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conversation_messages')->insert(['company_id' => $company->id, 'conversation_id' => $conversation,
                'author' => 'merchant', 'body' => 'مرحبا', 'created_at' => now()]);
            DB::table('pickup_requests')->insert(['company_id' => $company->id, 'merchant_id' => $merchant->id, 'number' => 'P1',
                'status' => 'pending', 'expected_count' => 1, 'actual_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    private function rows(Company $company, string $table): int
    {
        return DB::table($table)->where('company_id', $company->id)->count();
    }

    private function choose(array $extra = []): array
    {
        return $extra + ['company' => $this->company->id, 'mode' => 'all'];
    }

    public function test_nothing_is_deleted_before_confirming_with_slug_and_password(): void
    {
        $this->actingAs($this->admin)->get('/admin/purge')->assertOk()->assertSee('حذف البيانات')->assertSee('الزاجل');

        $this->actingAs($this->admin)->get('/admin/purge/preview?'.http_build_query($this->choose(['everything' => 1])))
            ->assertOk()->assertSee('تأكيد الحذف من الزاجل')->assertSee('كل الوقت')->assertSee('احذف نهائياً');
        $this->assertSame(2, $this->rows($this->company, 'shipments'));

        // اسمٌ خاطئ، وكلمة مرورٍ خاطئة، وبلا إقرار: لا شيء يُحذف
        foreach ([
            ['confirm_slug' => 'zajl', 'password' => 'secret-pass', 'understood' => 1],
            ['confirm_slug' => 'zajel', 'password' => 'wrong', 'understood' => 1],
            ['confirm_slug' => 'zajel', 'password' => 'secret-pass'],
        ] as $confirm) {
            $this->actingAs($this->admin)->post('/admin/purge', $this->choose(['parts' => ['shipments']]) + $confirm)
                ->assertSessionHasErrors();
        }
        $this->assertSame(2, $this->rows($this->company, 'shipments'));

        // دعم المنصّة يرى الصفحة ولا يحذف
        $support = Tenancy::runAsPlatform(fn () => User::create(['name' => 'دعم', 'phone' => '07700000009',
            'password' => 'secret-pass', 'role' => UserRole::PlatformSupport, 'is_active' => true]));
        $this->actingAs($support)->get('/admin/purge')->assertOk()->assertSee('الحذف لمدير المنصّة وحده');
        $this->actingAs($support)->get('/admin/purge/preview?'.http_build_query($this->choose(['parts' => ['shipments']])))->assertForbidden();
        $this->actingAs($support)->post('/admin/purge', $this->choose(['parts' => ['shipments'], 'confirm_slug' => 'zajel',
            'password' => 'secret-pass', 'understood' => 1]))->assertForbidden();
        $this->assertSame(2, $this->rows($this->company, 'shipments'));
    }

    public function test_everything_is_deleted_from_this_company_alone_and_the_owner_stays(): void
    {
        $before = collect(['shipments', 'transactions', 'journal_entries', 'merchants', 'couriers', 'users', 'conversation_messages', 'pickup_requests'])
            ->mapWithKeys(fn ($t) => [$t => $this->rows($this->other, $t)]);

        $this->actingAs($this->admin)->post('/admin/purge', $this->choose([
            'everything' => 1, 'confirm_slug' => 'zajel', 'password' => 'secret-pass', 'understood' => 1,
        ]))->assertRedirect('/admin/purge?company='.$this->company->id)->assertSessionHas('purged');

        foreach (['shipments', 'shipment_events', 'transactions', 'cash_movements', 'journal_entries', 'journal_lines', 'journal_daily',
            'merchant_settlements', 'merchants', 'couriers', 'conversations', 'conversation_messages', 'pickup_requests'] as $table) {
            $this->assertSame(0, $this->rows($this->company, $table), $table);
        }

        // صاحب الشركة وحده يبقى، ونظامها يفتح له
        $this->assertSame([UserRole::CompanyOwner->value], DB::table('users')->where('company_id', $this->company->id)->pluck('role')->all());
        $this->assertSame(1, $this->rows($this->company, 'branches'));
        $this->assertSame(0, (int) DB::table('journal_heads')->where('company_id', $this->company->id)->value('last_number'));

        // الشركة الأخرى كما كانت، ودفترها سليم
        foreach ($before as $table => $n) {
            $this->assertSame($n, $this->rows($this->other, $table), $table);
        }
        $this->assertNull(app(Journal::class)->brokenAt($this->other->id));

        $log = AuditLog::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('action', 'data_purged')->sole();
        $this->assertSame(2, $log->new_values['counts']['shipments']);
        $this->assertNull($log->new_values['from']);
        $this->actingAs($this->admin)->get('/admin/companies/'.$this->company->id)->assertOk()
            ->assertSee('حُذفت بيانات من المنصّة')->assertSee('الشحنات، الطلبات');
    }

    public function test_a_day_deletes_only_what_was_made_that_day(): void
    {
        // شحنةٌ من الأمس تُحذف، وما سواها يبقى
        $old = Tenancy::runFor($this->company, fn () => Shipment::query()->where('status', 'created')->sole());
        DB::table('shipments')->where('id', $old->id)->update(['created_at' => Carbon::yesterday()->setTime(15, 0)]);

        $this->actingAs($this->admin)->post('/admin/purge', $this->choose([
            'parts' => ['shipments'], 'mode' => 'day', 'day' => Carbon::yesterday()->toDateString(),
            'confirm_slug' => 'zajel', 'password' => 'secret-pass', 'understood' => 1,
        ]))->assertSessionHas('purged.counts.shipments', 1);

        $this->assertNull(DB::table('shipments')->find($old->id));
        $this->assertSame(1, $this->rows($this->company, 'shipments'));

        // ومدّةٌ لا شيء فيها لا تحذف شيئاً
        $this->actingAs($this->admin)->post('/admin/purge', $this->choose([
            'parts' => ['shipments'], 'mode' => 'range', 'from' => '2020-01-01', 'to' => '2020-01-31',
            'confirm_slug' => 'zajel', 'password' => 'secret-pass', 'understood' => 1,
        ]))->assertSessionHas('purged.counts', []);
        $this->assertSame(1, $this->rows($this->company, 'shipments'));

        // «إلى» قبل «من» مرفوضة
        $this->actingAs($this->admin)->get('/admin/purge/preview?'.http_build_query($this->choose([
            'parts' => ['shipments'], 'mode' => 'range', 'from' => '2026-02-01', 'to' => '2026-01-01',
        ])))->assertSessionHasErrors('to');
    }

    public function test_a_merchant_with_shipments_left_stays_and_money_rebuilds_balances_and_the_journal(): void
    {
        $merchant = Tenancy::runFor($this->company, fn () => Merchant::query()->sole());

        // التجّار والمناديب بلا شحناتهم ومالهم: يبقون
        $this->actingAs($this->admin)->post('/admin/purge', $this->choose([
            'parts' => ['merchants', 'couriers', 'staff'], 'confirm_slug' => 'zajel', 'password' => 'secret-pass', 'understood' => 1,
        ]))->assertSessionHas('purged.counts.kept_merchants', 1)->assertSessionHas('purged.counts.kept_couriers', 1)
            ->assertSessionHas('purged.counts.staff', 1);
        $this->assertNotNull(DB::table('merchants')->find($merchant->id));
        $this->assertSame(0, DB::table('users')->where('company_id', $this->company->id)->where('role', UserRole::Operations->value)->count());

        // المال وحده من آخر حركة: الرصيد يُعاد من الباقي، والدفتر يُعاد تسلسله فيسلم
        $last = DB::table('transactions')->where('company_id', $this->company->id)->orderByDesc('id')->first();
        DB::table('transactions')->where('id', $last->id)->update(['created_at' => Carbon::yesterday()]);
        DB::table('journal_entries')->where('company_id', $this->company->id)->orderByDesc('number')->limit(1)
            ->update(['entry_date' => Carbon::yesterday()->toDateString()]);
        $remaining = DB::table('transactions')->where('company_id', $this->company->id)->where('id', '!=', $last->id)->get();

        $this->actingAs($this->admin)->post('/admin/purge', $this->choose([
            'parts' => ['money'], 'mode' => 'day', 'day' => Carbon::yesterday()->toDateString(),
            'confirm_slug' => 'zajel', 'password' => 'secret-pass', 'understood' => 1,
        ]))->assertSessionHas('purged.counts.transactions', 1);

        $expected = $remaining->where('account_type', 'merchant')->sum(fn ($t) => $t->direction === 'credit' ? $t->amount : -$t->amount);
        $this->assertSame((int) $expected, (int) DB::table('merchants')->where('id', $merchant->id)->value('balance'));
        $this->assertNull(app(Journal::class)->brokenAt($this->company->id));
        $entries = DB::table('journal_entries')->where('company_id', $this->company->id)->count();
        $this->assertSame($entries, (int) DB::table('journal_heads')->where('company_id', $this->company->id)->value('last_number'));
        $this->assertSame(
            (int) DB::table('journal_lines')->where('company_id', $this->company->id)->sum('debit'),
            (int) DB::table('journal_daily')->where('company_id', $this->company->id)->sum('debit'),
        );

        // ثم الشحنات والمال كلّه: التاجر يُحذف مع حساب دخوله
        $this->actingAs($this->admin)->post('/admin/purge', $this->choose([
            'parts' => ['shipments', 'requests', 'money', 'merchants'], 'confirm_slug' => 'zajel', 'password' => 'secret-pass', 'understood' => 1,
        ]))->assertSessionHas('purged.counts.merchants', 1);
        $this->assertNull(DB::table('merchants')->find($merchant->id));
        $this->assertSame(0, DB::table('users')->where('merchant_id', $merchant->id)->count());
    }
}
