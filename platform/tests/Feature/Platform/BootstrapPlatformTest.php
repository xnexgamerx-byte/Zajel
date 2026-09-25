<?php

namespace Tests\Feature\Platform;

use App\Enums\UserRole;
use App\Models\Governorate;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * يعمل مع كل بدءٍ على Railway: فالحارس هنا أنه يجهّز مرّةً واحدة ولا يلمس
 * ما هو موجود — لا يعيد زرع باقاتٍ عُدِّلت، ولا يعيد كلمة مرورٍ غُيِّرت.
 */
class BootstrapPlatformTest extends TestCase
{
    use RefreshDatabase;

    private function admins()
    {
        return Tenancy::runAsPlatform(fn () => User::whereNull('company_id')->where('role', UserRole::PlatformAdmin)->get());
    }

    public function test_an_empty_database_gets_reference_data_and_the_admin_from_settings(): void
    {
        config(['zajel.bootstrap_admin' => ['phone' => '0770 123 4567', 'password' => 'a-long-secret']]);

        $this->artisan('zajel:bootstrap')->assertExitCode(0);

        $this->assertGreaterThan(0, Governorate::count());
        $this->assertCount(1, $this->admins());
        $this->assertSame('07701234567', $this->admins()->first()->phone);

        $this->post('/admin/login', ['phone' => '07701234567', 'password' => 'a-long-secret'])
            ->assertRedirect('/admin');
    }

    public function test_running_again_changes_nothing(): void
    {
        config(['zajel.bootstrap_admin' => ['phone' => '07701234567', 'password' => 'a-long-secret']]);
        $this->artisan('zajel:bootstrap');

        // غيّر المدير كلمة مروره، وبقي المتغيّر القديم في الإعدادات
        $this->admins()->first()->update(['password' => 'changed-since-then']);
        config(['zajel.bootstrap_admin' => ['phone' => '07709999999', 'password' => 'another-secret']]);

        $this->artisan('zajel:bootstrap')->assertExitCode(0);

        $this->assertCount(1, $this->admins());
        $this->assertTrue(Hash::check('changed-since-then', $this->admins()->first()->password));
    }

    public function test_without_settings_or_with_a_short_password_no_admin_is_made(): void
    {
        config(['zajel.bootstrap_admin' => ['phone' => null, 'password' => null]]);
        $this->artisan('zajel:bootstrap')->assertExitCode(0);
        $this->assertCount(0, $this->admins());

        config(['zajel.bootstrap_admin' => ['phone' => '07701234567', 'password' => 'short']]);
        $this->artisan('zajel:bootstrap')->assertExitCode(0);
        $this->assertCount(0, $this->admins());

        // والبيانات المرجعية زُرعت في الحالين
        $this->assertGreaterThan(0, Governorate::count());
    }
}
