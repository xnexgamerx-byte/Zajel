<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Phone;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * أوّل حسابٍ على خادمٍ جديد: مدير المنصّة، ومنه تُسجَّل الشركات.
 *
 * بيانات العرض لا تُزرَع في الإنتاج، فلا حساب يُدخَل به قبل هذا الأمر.
 * ويُعاد تشغيله بالرقم نفسه لتغيير كلمة المرور إن نُسيت.
 *
 *   php artisan zajel:admin 07701234567 --name="أحمد"
 */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'zajel:admin
        {phone : رقم هاتف المدير، به يدخل}
        {--name=مدير المنصّة : الاسم الظاهر}';

    protected $description = 'ينشئ مدير المنصّة، أو يغيّر كلمة مروره';

    public function handle(): int
    {
        $phone = Phone::normalise((string) $this->argument('phone'));

        if ($phone === null) {
            $this->error('رقم الهاتف غير صحيح. اكتبه كما في الهوية: 07xxxxxxxxx');

            return self::FAILURE;
        }

        // تُكتَب مخفيّةً ولا تُمرَّر خياراً: ما يُكتب في سطر الأوامر يبقى في سجلّ الطرفية
        $password = (string) $this->secret('كلمة المرور (١٠ أحرف على الأقل)');

        if (mb_strlen($password) < 10) {
            $this->error('كلمة المرور أقصر من عشرة أحرف.');

            return self::FAILURE;
        }

        if ($password !== (string) $this->secret('أعد كتابتها')) {
            $this->error('الكلمتان مختلفتان.');

            return self::FAILURE;
        }

        $user = Tenancy::runAsPlatform(fn () => User::updateOrCreate(
            ['company_id' => null, 'phone' => $phone],
            [
                'name' => (string) $this->option('name'), 'password' => $password,
                'role' => UserRole::PlatformAdmin, 'is_active' => true,
            ],
        ));

        $this->info(($user->wasRecentlyCreated ? 'أُنشئ' : 'حُدِّث').' مدير المنصّة '.$phone.'.');
        $this->line('يدخل من: https://admin.'.config('zajel.tenant_domain').'/admin/login');

        return self::SUCCESS;
    }
}
