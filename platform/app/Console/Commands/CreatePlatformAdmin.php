<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Phone;
use App\Support\Tenancy\Tenancy;
use App\Support\Username;
use Illuminate\Console\Command;

/**
 * أوّل حسابٍ على خادمٍ جديد: مدير المنصّة، ومنه تُسجَّل الشركات.
 *
 * بيانات العرض لا تُزرَع في الإنتاج، فلا حساب يُدخَل به قبل هذا الأمر.
 * ويُعاد تشغيله بالرقم نفسه لتغيير كلمة المرور إن نُسيت، أو اسم المستخدم.
 *
 *   php artisan zajel:admin 07701234567 --name="أحمد" --username=ahmed
 *
 * يدخل باسم المستخدم، وهو رقم الهاتف ما لم يُختر غيره.
 */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'zajel:admin
        {phone : رقم هاتف المدير، به يُعرف حسابه}
        {--name=مدير المنصّة : الاسم الظاهر}
        {--username= : اسم المستخدم للدخول (افتراضياً رقم الهاتف)}';

    protected $description = 'ينشئ مدير المنصّة، أو يغيّر كلمة مروره';

    public function handle(): int
    {
        $phone = Phone::normalise((string) $this->argument('phone'));

        if ($phone === null) {
            $this->error('رقم الهاتف غير صحيح. اكتبه كما في الهوية: 07xxxxxxxxx');

            return self::FAILURE;
        }

        $username = null;

        if (filled($this->option('username'))) {
            $username = Username::normalise((string) $this->option('username'));

            if ($username === null) {
                $this->error(Username::RULE_MESSAGE);

                return self::FAILURE;
            }

            // فريدٌ بين مستخدمي المنصّة: الفهرس لا يمنعه حين company_id فارغ
            $taken = Tenancy::runAsPlatform(fn () => User::whereNull('company_id')
                ->where('username', $username)
                ->where('phone', '!=', $phone)
                ->exists());

            if ($taken) {
                $this->error("اسم المستخدم {$username} لمديرٍ آخر.");

                return self::FAILURE;
            }
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
            ] + ($username !== null ? ['username' => $username] : []),
        ));

        $this->info(($user->wasRecentlyCreated ? 'أُنشئ' : 'حُدِّث').' مدير المنصّة '.$phone.'.');
        $this->line('يدخل باسم المستخدم: '.$user->username);
        $this->line('من: https://admin.'.config('zajel.tenant_domain').'/admin/login');

        return self::SUCCESS;
    }
}
