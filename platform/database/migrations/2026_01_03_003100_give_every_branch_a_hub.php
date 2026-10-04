<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * لكل فرعٍ مركز فرز (docs/plan/32).
 *
 * التسجيل يُنشئ مركزاً للفرع الرئيسي وحده، وما أُضيف من «الفروع» بقي بلا مركز: لا يُرسَل
 * إليه كيسٌ ولا كشف، وما يستلمه موظّفه يُسجَّل في مخزن الرئيسي. من اليوم يُنشأ مع الفرع
 * (Hub::ensureFor)، وهذا لما أُضيف قبله. لا يمسّ فرعاً له مركز.
 */
return new class extends Migration
{
    public function up(): void
    {
        $branches = DB::table('branches')
            ->whereNull('deleted_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('hubs')
                ->whereColumn('hubs.branch_id', 'branches.id')->where('hubs.is_active', true))
            ->get(['id', 'company_id', 'name', 'governorate_id', 'is_main']);

        foreach ($branches as $branch) {
            $code = 'HUB-'.$branch->id;

            if (DB::table('hubs')->where('company_id', $branch->company_id)->where('code', $code)->exists()) {
                $code .= '-'.Str::lower(Str::random(4));
            }

            DB::table('hubs')->insert([
                'company_id'     => $branch->company_id,
                'branch_id'      => $branch->id,
                'code'           => $code,
                'name'           => Str::limit('مركز فرز '.$branch->name, 160, ''),
                'type'           => $branch->is_main ? 'main' : 'branch',
                'governorate_id' => $branch->governorate_id,
                'is_active'      => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    public function down(): void
    {
        // مراكز أُنشئت وقد تحمل أكياساً وشحنات: لا تُحذف بالرجوع
    }
};
