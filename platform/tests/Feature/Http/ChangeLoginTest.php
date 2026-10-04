<?php

namespace Tests\Feature\Http;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * اسم الدخول وكلمة المرور يُغيَّران لكل حساب — مندوبٍ وتاجرٍ وموظّف — من نموذج تعديله.
 * وكلمة مرورٍ جديدة تُخرج صاحبها من أجهزته، والتغيير في سجلّ التدقيق بلا كلمة المرور.
 */
class ChangeLoginTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->owner = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function courierPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'عباس', 'phone' => '07755667788', 'type' => 'delivery', 'vehicle_type' => 'motorcycle',
            'commission_per_delivery' => 3000, 'cash_limit' => 0, 'status' => 'active',
        ], $overrides);
    }

    private function merchantPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'أزياء الرشيد', 'owner_name' => 'سيف الرشيد', 'phone' => '07733445566',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد - الكرادة', 'settlement_cycle' => 'weekly',
            'payout_method' => 'zaincash', 'payout_account' => '07733445566', 'status' => 'active',
        ], $overrides);
    }

    private function deviceSession(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => 'device-of-'.$user->id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'phone', 'payload' => '', 'last_activity' => now()->timestamp,
        ]);
    }

    private function account(string $username): ?User
    {
        return Tenancy::runFor($this->company, fn () => User::where('username', $username)->first());
    }

    public function test_a_couriers_username_and_password_are_changed_from_his_form(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/couriers', $this->courierPayload([
            'create_login' => 1, 'username' => 'abbas', 'password' => 'first-pass',
        ]))->assertSessionHasNoErrors();

        $courier = Tenancy::runFor($this->company, fn () => Courier::firstOrFail());
        $account = $this->account('abbas');
        $this->deviceSession($account);

        // النموذج يعرض حسابه القائم
        $this->actingAs($this->owner)->get($this->host().'/couriers/'.$courier->id.'/edit')
            ->assertOk()->assertSee('value="abbas"', false)->assertSee('كلمة مرورٍ جديدة');

        $this->actingAs($this->owner)->put($this->host().'/couriers/'.$courier->id, $this->courierPayload([
            'username' => 'abbas.k', 'password' => 'second-pass',
        ]))->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame('abbas.k', $account->username);
        $this->assertTrue(Hash::check('second-pass', $account->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $account->id)->count(), 'خرج من أجهزته');

        $log = Tenancy::runFor($this->company, fn () => AuditLog::where('action', 'login_changed')->sole());
        $this->assertSame(['abbas', 'abbas.k', true], [$log->old_values['username'], $log->new_values['username'], $log->new_values['password_changed']]);
        $this->assertStringNotContainsString('second-pass', json_encode($log->new_values));

        // كلمة مرورٍ فارغة لا تغيّر شيئاً، واسمٌ يحمله غيره يُرفض
        $this->actingAs($this->owner)->put($this->host().'/couriers/'.$courier->id, $this->courierPayload(['username' => 'abbas.k']))
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('second-pass', $account->fresh()->password));

        $this->actingAs($this->owner)->put($this->host().'/couriers/'.$courier->id, $this->courierPayload(['username' => $this->owner->username]))
            ->assertSessionHasErrors(['username' => 'اسم المستخدم هذا لحسابٍ آخر في شركتك.']);
        $this->assertSame('abbas.k', $account->fresh()->username);

        // ويدخل بالاسم الجديد
        auth()->logout();
        $this->post($this->host().'/login', ['username' => 'abbas.k', 'password' => 'second-pass'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($account->fresh());
    }

    public function test_a_courier_without_an_account_gets_one_from_his_edit_form(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/couriers', $this->courierPayload())->assertSessionHasNoErrors();
        $courier = Tenancy::runFor($this->company, fn () => Courier::firstOrFail());

        $this->actingAs($this->owner)->get($this->host().'/couriers/'.$courier->id.'/edit')
            ->assertOk()->assertSee('أنشئ حساباً له على تطبيق المندوبين');

        $this->actingAs($this->owner)->put($this->host().'/couriers/'.$courier->id, $this->courierPayload(['create_login' => 1]))
            ->assertSessionHasErrors(['password' => 'أدخل كلمة مرور لحساب دخول المندوب.']);

        $this->actingAs($this->owner)->put($this->host().'/couriers/'.$courier->id, $this->courierPayload([
            'create_login' => 1, 'username' => 'abbas', 'password' => 'secret-1',
        ]))->assertSessionHasNoErrors();

        $account = $this->account('abbas');
        $this->assertNotNull($account);
        $this->assertSame([UserRole::Courier, $courier->id], [$account->role, $account->courier_id]);
        $this->assertSame($account->id, Tenancy::runFor($this->company, fn () => $courier->fresh()->user_id));
    }

    public function test_a_merchants_login_is_changed_from_his_form(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/merchants', $this->merchantPayload([
            'create_login' => 1, 'username' => 'rasheed', 'password' => 'first-pass',
        ]))->assertSessionHasNoErrors();
        $merchant = Tenancy::runFor($this->company, fn () => Merchant::firstOrFail());
        $account = $this->account('rasheed');

        $this->actingAs($this->owner)->get($this->host().'/merchants/'.$merchant->id.'/edit')
            ->assertOk()->assertSee('value="rasheed"', false)->assertSee('بوابة التجّار');

        $this->actingAs($this->owner)->put($this->host().'/merchants/'.$merchant->id, $this->merchantPayload([
            'username' => 'rasheed.fashion', 'password' => 'second-pass',
        ]))->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame('rasheed.fashion', $account->username);
        $this->assertTrue(Hash::check('second-pass', $account->password));
    }

    public function test_a_staff_members_new_password_signs_them_out_of_their_devices(): void
    {
        $agent = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'زينب', 'phone' => '07701110001', 'username' => 'zainab', 'password' => 'first-pass',
            'role' => UserRole::CustomerService, 'is_active' => true,
        ]));
        $this->deviceSession($agent);

        $this->actingAs($this->owner)->put($this->host().'/users/'.$agent->id, [
            'name' => 'زينب', 'username' => 'zainab.cs', 'phone' => '07701110001',
            'role' => UserRole::CustomerService->value, 'password' => 'second-pass', 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $agent->refresh();
        $this->assertSame('zainab.cs', $agent->username);
        $this->assertTrue(Hash::check('second-pass', $agent->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $agent->id)->count());
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => AuditLog::where('action', 'login_changed')->count()));
    }
}
