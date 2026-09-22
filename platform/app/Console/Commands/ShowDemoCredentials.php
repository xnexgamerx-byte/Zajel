<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;

class ShowDemoCredentials extends Command
{
    protected $signature = 'zajel:demo-credentials';

    protected $description = 'يعرض حسابات الدخول التجريبية لكل شركة';

    public function handle(): int
    {
        $rows = [];

        Tenancy::runAsPlatform(function () use (&$rows) {
            foreach (User::whereNull('company_id')->get() as $user) {
                $rows[] = ['النواة', $user->name, $user->phone, $user->role->label()];
            }
        });

        foreach (Company::all() as $company) {
            Tenancy::runFor($company, function (Company $company) use (&$rows) {
                $owner = User::where('role', UserRole::CompanyOwner)->first();

                if ($owner) {
                    $rows[] = [$company->slug, $owner->name, $owner->phone, $owner->role->label()];
                }
            });
        }

        $this->table(['الشركة', 'الاسم', 'الهاتف', 'الدور'], $rows);
        $this->line('  كلمة المرور للجميع: password');
        $this->line('  محلياً: http://127.0.0.1:8000/login?company=zajel');

        return self::SUCCESS;
    }
}
