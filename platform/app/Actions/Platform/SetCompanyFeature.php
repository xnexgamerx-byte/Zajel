<?php

namespace App\Actions\Platform;

use App\Enums\Feature;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyFeature;
use App\Models\Scopes\CurrentCompanyScope;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * يفتح صاحب المنصّة ميزةً لشركةٍ أو يغلقها، ويحدّد رسمها الشهريّ (docs/plan/35).
 *
 * القرار الساري يُغلق ويبدأ غيره، فيبقى تاريخ كل ميزة: متى فُتحت وبكم، ومتى أُغلقت —
 * ومنه تُحسب الفاتورة يوماً بيوم (GenerateInvoice). وما لم يتغيّر لا يُكتب.
 * والميزة المغلقة بلا رسم: لا يُدفع على ما لا يُستعمل.
 */
final class SetCompanyFeature
{
    /** @return bool تغيّر شيء */
    public function handle(Company $company, Feature $feature, bool $enabled, int $price, User $by, ?string $ip = null): bool
    {
        $price = $enabled ? max(0, $price) : 0;

        $changed = DB::transaction(function () use ($company, $feature, $enabled, $price, $by, $ip) {
            // قراران معاً على شركةٍ واحدة لا يتركان قرارين ساريين في ميزة
            Company::withoutGlobalScope(CurrentCompanyScope::class)->whereKey($company->id)->lockForUpdate()->first();

            $current = CompanyFeature::acrossCompanies()
                ->where('company_id', $company->id)
                ->where('feature', $feature->value)
                ->current()
                ->first();

            $was = ['enabled' => $current ? $current->enabled : $feature->included(), 'monthly_price' => $current?->monthly_price ?? 0];

            if ($was === ['enabled' => $enabled, 'monthly_price' => $price]) {
                return false;
            }

            $now = now();
            $current?->update(['ends_at' => $now]);

            // في سياق الشركة نفسها: صفوفها تحمل هويّتها مهما كان السياق قبلها
            Tenancy::runFor($company, function () use ($company, $feature, $enabled, $price, $by, $ip, $now, $was) {
                CompanyFeature::create([
                    'company_id'    => $company->id,
                    'feature'       => $feature->value,
                    'enabled'       => $enabled,
                    'monthly_price' => $price,
                    'starts_at'     => $now,
                    'user_id'       => $by->id,
                ]);

                AuditLog::create([
                    'company_id'     => $company->id,
                    'user_id'        => $by->id,
                    'user_name'      => $by->name,
                    'action'         => match (true) {
                        $enabled && ! $was['enabled'] => 'feature_enabled',
                        ! $enabled                    => 'feature_disabled',
                        default                       => 'feature_price_changed',
                    },
                    'auditable_type' => Company::class,
                    'auditable_id'   => $company->id,
                    'old_values'     => ['feature' => $feature->value] + $was,
                    'new_values'     => ['feature' => $feature->value, 'enabled' => $enabled, 'monthly_price' => $price],
                    'ip'             => $ip,
                ]);
            });

            return true;
        });

        $company->forgetFeatures();

        return $changed;
    }
}
