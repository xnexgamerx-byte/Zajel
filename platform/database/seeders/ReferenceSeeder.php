<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * البيانات المرجعية وحدها — ما يحتاجه كل خادم، بلا بيانات عرض أياً كانت البيئة.
 */
class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            GovernorateSeeder::class,
            FailureReasonSeeder::class,
            ExpenseCategorySeeder::class,
            PlanSeeder::class,
        ]);
    }
}
