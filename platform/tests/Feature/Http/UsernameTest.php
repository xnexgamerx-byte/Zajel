<?php

namespace Tests\Feature\Http;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Username;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اسم المستخدم حيث تُنشأ الحسابات: الموظّف، والمندوب، والتاجر، وصاحب الشركة،
 * ومدير المنصّة. مختارٌ بصيغته المحفوظة، أو رقم الهاتف إن تُرك فارغاً — وفريدٌ
 * داخل الشركة، فلا يصطدم اسمٌ برقمٍ اختاره آخر اسماً له.
 */
class UsernameTest extends TestCase
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

    private function host(string $slug = 'zajel'): string
    {
        return 'http://'.$slug.'.'.config('zajel.tenant_domain');
    }

    private function staffPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'زينب العبيدي', 'phone' => '07733445566',
            'role' => 'customer_service', 'password' => 'secret123', 'is_active' => 1,
        ], $overrides);
    }

    private function inCompany(callable $callback): mixed
    {
        return Tenancy::runFor($this->company, $callback);
    }

    // ------------------------------------------------------------ الموظّفون

    public function test_a_chosen_username_is_saved_in_its_one_form_and_logs_in(): void
    {
        $this->actingAs($this->owner)
            ->post($this->host().'/users', $this->staffPayload(['username' => ' Zainab.Obaidi ']))
            ->assertSessionHas('success');

        $user = $this->inCompany(fn () => User::where('phone', '07733445566')->firstOrFail());
        $this->assertSame('zainab.obaidi', $user->username);

        auth()->logout();
        $this->post($this->host().'/login', ['username' => 'zainab.obaidi', 'password' => 'secret123'])
            ->assertRedirect($this->host());
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_empty_username_becomes_the_phone(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/users', $this->staffPayload());

        $this->assertSame('07733445566', $this->inCompany(fn () => User::where('phone', '07733445566')->value('username')));
    }

    public function test_a_username_taken_inside_the_company_is_refused(): void
    {
        $this->inCompany(fn () => $this->owner->update(['username' => 'ali']));

        $this->actingAs($this->owner)
            ->post($this->host().'/users', $this->staffPayload(['username' => 'ALI']))
            ->assertSessionHasErrors('username');
    }

    public function test_a_phone_that_another_account_chose_as_its_name_is_not_reused_silently(): void
    {
        $this->inCompany(fn () => $this->owner->update(['username' => '07733445566']));

        // فارغاً يصير الاسم رقمَ الهاتف — وهو مأخوذ: رسالةٌ لا خطأ ٥٠٠ من الفهرس
        $this->actingAs($this->owner)
            ->post($this->host().'/users', $this->staffPayload())
            ->assertSessionHasErrors('username');
    }

    public function test_the_same_username_is_free_at_another_company(): void
    {
        $this->inCompany(fn () => $this->owner->update(['username' => 'ali']));

        $other = $this->makeCompany('barq', 'البرق');
        $otherOwner = $this->makeUser($other);

        $this->actingAs($otherOwner)
            ->post($this->host('barq').'/users', $this->staffPayload(['username' => 'ali']))
            ->assertSessionHas('success');
    }

    public function test_arabic_letters_and_spaces_are_refused_with_the_rule(): void
    {
        foreach (['علي', 'ali salam', 'a', '.ali', 'ali.'] as $bad) {
            $this->actingAs($this->owner)
                ->post($this->host().'/users', $this->staffPayload(['username' => $bad]))
                ->assertSessionHasErrors(['username' => Username::RULE_MESSAGE]);
        }
    }

    public function test_editing_can_change_the_username_and_an_empty_field_keeps_it(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/users', $this->staffPayload(['username' => 'zainab']));
        $user = $this->inCompany(fn () => User::where('username', 'zainab')->firstOrFail());

        $this->actingAs($this->owner)
            ->put($this->host().'/users/'.$user->id, $this->staffPayload(['username' => 'zainab.o']))
            ->assertSessionHas('success');
        $this->assertSame('zainab.o', $this->inCompany(fn () => $user->fresh()->username));

        $this->actingAs($this->owner)
            ->put($this->host().'/users/'.$user->id, $this->staffPayload(['username' => '']))
            ->assertSessionHas('success');
        $this->assertSame('zainab.o', $this->inCompany(fn () => $user->fresh()->username));
    }

    public function test_the_staff_list_shows_and_finds_the_username(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/users', $this->staffPayload(['username' => 'zainab']));

        $this->actingAs($this->owner)->get($this->host().'/users?q=ZAINAB')
            ->assertOk()
            ->assertSee('zainab')
            ->assertSee('زينب العبيدي');
    }

    // ---------------------------------------------------- المندوب والتاجر

    public function test_a_courier_login_takes_a_chosen_username(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/couriers', [
            'name' => 'سجاد الموسوي', 'phone' => '07755667788', 'type' => 'delivery',
            'vehicle_type' => 'motorcycle', 'status' => 'active',
            'create_login' => 1, 'username' => 'Sajjad', 'password' => 'secret123',
        ])->assertSessionHas('success');

        $this->assertSame('sajjad', $this->inCompany(fn () => Courier::firstOrFail()->user->username));
    }

    public function test_a_merchant_login_without_a_username_uses_the_phone(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/merchants', [
            'business_name' => 'أزياء الرشيد', 'owner_name' => 'سيف', 'phone' => '07733440011',
            'governorate_id' => $this->baghdad()->id, 'address' => 'الكرادة',
            'settlement_cycle' => 'weekly', 'payout_method' => 'cash', 'status' => 'active',
            'create_login' => 1, 'password' => 'secret123',
        ])->assertSessionHas('success');

        $user = $this->inCompany(fn () => User::where('merchant_id', Merchant::firstOrFail()->id)->firstOrFail());
        $this->assertSame('07733440011', $user->username);
    }

    public function test_a_taken_username_for_a_courier_login_is_refused(): void
    {
        $this->inCompany(fn () => $this->owner->update(['username' => 'sajjad']));

        $this->actingAs($this->owner)->post($this->host().'/couriers', [
            'name' => 'سجاد الموسوي', 'phone' => '07755667788', 'type' => 'delivery',
            'vehicle_type' => 'motorcycle', 'status' => 'active',
            'create_login' => 1, 'username' => 'sajjad', 'password' => 'secret123',
        ])->assertSessionHasErrors('username');

        $this->assertSame(0, $this->inCompany(fn () => Courier::count()));
    }

    // ------------------------------------------------ صاحب الشركة والمنصّة

    public function test_the_owner_of_a_registered_company_logs_in_with_the_chosen_username(): void
    {
        $admin = Tenancy::runAsPlatform(fn () => User::create([
            'company_id' => null, 'name' => 'مدير المنصّة', 'phone' => '07700000000',
            'password' => 'password', 'role' => UserRole::PlatformAdmin, 'is_active' => true,
        ]));

        $this->actingAs($admin)->post('/admin/companies', [
            'name' => 'البرق للتوصيل', 'slug' => 'barq', 'status' => 'trial',
            'owner_name' => 'سيف البرق', 'owner_username' => 'Saif.Barq', 'owner_phone' => '07711112222',
            'owner_password' => 'secret123', 'plan_id' => Plan::where('code', 'growth')->value('id'),
            'billing_cycle' => 'monthly',
        ])->assertSessionHas('success', fn ($message) => str_contains($message, 'saif.barq'));

        auth()->logout();
        $this->post($this->host('barq').'/login', ['username' => 'saif.barq', 'password' => 'secret123'])
            ->assertRedirect($this->host('barq'));
    }

    public function test_the_admin_command_sets_a_username_and_refuses_a_taken_one(): void
    {
        $this->artisan('zajel:admin', ['phone' => '07701234567', '--username' => 'Ahmed'])
            ->expectsQuestion('كلمة المرور (١٠ أحرف على الأقل)', 'a-long-secret')
            ->expectsQuestion('أعد كتابتها', 'a-long-secret')
            ->expectsOutputToContain('يدخل باسم المستخدم: ahmed')
            ->assertExitCode(0);

        $this->post('/admin/login', ['username' => 'ahmed', 'password' => 'a-long-secret'])
            ->assertRedirect('/admin');

        // الاسم يُفحص قبل كلمة المرور: لا يُسأل عنها لحسابٍ لن يُنشأ
        $this->artisan('zajel:admin', ['phone' => '07709999999', '--username' => 'ahmed'])
            ->expectsOutputToContain('لمديرٍ آخر')
            ->assertExitCode(1);

        $this->artisan('zajel:admin', ['phone' => '07709999999', '--username' => 'أحمد'])
            ->assertExitCode(1);
    }
}
