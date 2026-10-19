<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * تقسيم صلاحيات الأرباح (docs/plan/68 §١٣):
 * - «العوائد» (money.profits) تصير العرض (profits.view) وإعداد الوجبة (batches.prepare) واعتمادها (batches.approve).
 * - «الشطب والاعتماد باستثناء» (money.write_off) تصير صلاحيتين: batches.write_off وbatches.approve_exception.
 * ومن كان يملك القديمة يملك ما انقسمت إليه، فلا يتغيّر ما يفعله أحدٌ اليوم.
 */
return new class extends Migration
{
    private const SPLIT = [
        'money.profits'   => ['profits.view', 'batches.prepare', 'batches.approve'],
        'money.write_off' => ['batches.write_off', 'batches.approve_exception'],
    ];

    public function up(): void
    {
        foreach (self::SPLIT as $old => $new) {
            $this->swap([$old], $new);
        }
    }

    public function down(): void
    {
        foreach (self::SPLIT as $old => $new) {
            // من يملك أوّل ما انقسمت إليه يعود له الاسم القديم
            $this->swap($new, [$old], $new[0]);
        }
        $this->swap(['finance.settings'], []);
    }

    /**
     * @param  list<string>  $from
     * @param  list<string>  $to
     */
    private function swap(array $from, array $to, ?string $marker = null): void
    {
        $marker ??= $from[0];

        foreach (DB::table('ranks')->get(['id', 'abilities']) as $rank) {
            $abilities = json_decode((string) $rank->abilities, true);

            if (! is_array($abilities) || ! array_intersect($from, $abilities)) {
                continue;
            }

            $kept = array_diff($abilities, $from);
            $next = in_array($marker, $abilities, true) ? [...$kept, ...$to] : $kept;
            DB::table('ranks')->where('id', $rank->id)->update(['abilities' => json_encode(array_values(array_unique($next)))]);
        }

        foreach (DB::table('user_grants')->where('ability', $marker)->get() as $grant) {
            foreach ($to as $ability) {
                DB::table('user_grants')->updateOrInsert(
                    ['user_id' => $grant->user_id, 'ability' => $ability],
                    ['company_id' => $grant->company_id, 'note' => $grant->note, 'granted_by_user_id' => $grant->granted_by_user_id,
                        'granted_by_name' => $grant->granted_by_name, 'created_at' => $grant->created_at, 'updated_at' => now()],
                );
            }
        }
        DB::table('user_grants')->whereIn('ability', $from)->delete();
    }
};
