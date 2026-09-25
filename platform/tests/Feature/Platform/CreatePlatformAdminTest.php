<?php

namespace Tests\Feature\Platform;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * على خادمٍ جديد لا بيانات عرض: هذا الأمر هو الباب الأوّل، فيُحرَس أنه
 * يُنشئ مديراً للمنصّة لا لشركة، وأنه يغيّر كلمة المرور بالرقم نفسه.
 */
class CreatePlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $phone): ?User
    {
        return Tenancy::runAsPlatform(fn () => User::where('company_id', null)->where('phone', $phone)->first());
    }

    public function test_it_creates_a_platform_admin_who_can_log_in(): void
    {
        $this->artisan('zajel:admin', ['phone' => '0770 123 4567', '--name' => 'أحمد'])
            ->expectsQuestion('كلمة المرور (١٠ أحرف على الأقل)', 'a-long-secret')
            ->expectsQuestion('أعد كتابتها', 'a-long-secret')
            ->assertExitCode(0);

        $admin = $this->admin('07701234567');
        $this->assertNotNull($admin);
        $this->assertSame(UserRole::PlatformAdmin, $admin->role);
        $this->assertSame('أحمد', $admin->name);

        $this->post('/admin/login', ['phone' => '07701234567', 'password' => 'a-long-secret'])
            ->assertRedirect('/admin');
    }

    public function test_running_it_again_changes_the_password(): void
    {
        foreach (['first-secret-1', 'second-secret-2'] as $password) {
            $this->artisan('zajel:admin', ['phone' => '07701234567'])
                ->expectsQuestion('كلمة المرور (١٠ أحرف على الأقل)', $password)
                ->expectsQuestion('أعد كتابتها', $password)
                ->assertExitCode(0);
        }

        $this->assertSame(1, Tenancy::runAsPlatform(fn () => User::where('phone', '07701234567')->count()));
        $this->assertTrue(Hash::check('second-secret-2', $this->admin('07701234567')->password));
    }

    public function test_it_refuses_a_short_or_mistyped_password(): void
    {
        $this->artisan('zajel:admin', ['phone' => '07701234567'])
            ->expectsQuestion('كلمة المرور (١٠ أحرف على الأقل)', 'short')
            ->assertExitCode(1);

        $this->artisan('zajel:admin', ['phone' => '07701234567'])
            ->expectsQuestion('كلمة المرور (١٠ أحرف على الأقل)', 'a-long-secret')
            ->expectsQuestion('أعد كتابتها', 'a-long-secreT')
            ->assertExitCode(1);

        $this->assertNull($this->admin('07701234567'));
    }
}
