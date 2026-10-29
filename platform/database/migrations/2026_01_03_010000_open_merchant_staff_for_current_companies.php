<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «موظّفو التاجر» تُفتح الآن لكلّ شركةٍ قائمة (docs/plan/96)، بقرارٍ منها كما تفتحها لوحة المنصّة: صفٌّ ساريٌّ مفتوحٌ
 * بلا رسم. والشركة التي تُضاف بعدها تبدؤها مطفأةً كأيّ ميزةٍ جديدة (docs/plan/35)، وتُغلق لشركةٍ من اللوحة متى شئت.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $current = DB::table('company_features')
                ->where('company_id', $companyId)->where('feature', 'merchant_staff')->whereNull('ends_at')
                ->orderByDesc('id')->first();

            if ($current?->enabled) {
                continue;
            }

            if ($current) {
                DB::table('company_features')->where('id', $current->id)->update(['ends_at' => $now, 'updated_at' => $now]);
            }

            DB::table('company_features')->insert([
                'company_id' => $companyId,
                'feature' => 'merchant_staff',
                'enabled' => true,
                'monthly_price' => $current->monthly_price ?? 0,
                'starts_at' => $now,
                'ends_at' => null,
                'user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // قرارٌ في البيانات: يُغلق من لوحة المنصّة لا بالرجوع
    }
};
