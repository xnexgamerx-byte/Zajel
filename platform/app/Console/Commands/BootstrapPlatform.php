<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Governorate;
use App\Models\User;
use App\Support\Phone;
use App\Support\Tenancy\Tenancy;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Console\Command;

/**
 * يجهّز خادماً بلا طرفية (Railway): يُشغَّل مع كل بدء، ولا يغيّر شيئاً موجوداً.
 *
 * - البيانات المرجعية (المحافظات، أسباب التعذّر، الباقات) إن كانت القاعدة فارغة؛
 * - ومدير المنصّة من ZAJEL_ADMIN_PHONE وZAJEL_ADMIN_PASSWORD إن لم يكن للمنصّة
 *   مديرٌ بعد. ولا يغيّر كلمة مرور مديرٍ موجود: متغيّرٌ نُسي في الإعدادات لا
 *   يُعيد كلمةً قديمة كلّ نشر.
 */
class BootstrapPlatform extends Command
{
    protected $signature = 'zajel:bootstrap';

    protected $description = 'يزرع البيانات المرجعية وينشئ مدير المنصّة من الإعدادات — مرّةً واحدة';

    public function handle(): int
    {
        // المرجعية وحدها، لا DatabaseSeeder: بيانات العرض لا تدخل خادماً حقيقياً
        // ولو ضُبطت بيئته خطأً على local
        if (Governorate::count() === 0) {
            $this->call('db:seed', ['--class' => ReferenceSeeder::class, '--force' => true]);
        }

        $phone = Phone::normalise((string) config('zajel.bootstrap_admin.phone'));
        $password = (string) config('zajel.bootstrap_admin.password');

        $hasAdmin = Tenancy::runAsPlatform(fn () => User::whereNull('company_id')
            ->where('role', UserRole::PlatformAdmin)
            ->exists());

        if ($hasAdmin || $phone === null) {
            return self::SUCCESS;
        }

        if (mb_strlen($password) < 10) {
            $this->error('ZAJEL_ADMIN_PASSWORD أقصر من عشرة أحرف: لم يُنشأ مدير المنصّة.');

            return self::SUCCESS;
        }

        Tenancy::runAsPlatform(fn () => User::create([
            'company_id' => null, 'phone' => $phone, 'name' => 'مدير المنصّة',
            'password' => $password, 'role' => UserRole::PlatformAdmin, 'is_active' => true,
        ]));

        $this->info("أُنشئ مدير المنصّة {$phone}. احذف ZAJEL_ADMIN_PASSWORD من الإعدادات الآن.");

        return self::SUCCESS;
    }
}
