<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * أبواب المصروف الافتراضية (company_id = null) — متاحة لكل الشركات.
 * التصنيف ليس تجميلاً: هو ما يسمح بالقول إن ٤٠٪ من مصروف الشهر وقود،
 * بدل جدول مصروفات لا يُقرأ منه شيء.
 */
class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['fuel',            'وقود',                  'vehicles',  10],
            ['vehicle_repair',  'صيانة مركبات',          'vehicles',  20],
            ['vehicle_rent',    'إيجار مركبات',          'vehicles',  30],
            ['salaries',        'رواتب',                 'staff',     40],
            ['agent_bonus',     'حوافز المندوبين',       'staff',     50],
            ['staff_meals',     'ضيافة وطعام',           'staff',     60],
            ['rent',            'إيجار مخزن أو مكتب',    'overhead',  70],
            ['utilities',       'كهرباء وماء ومولّدة',   'overhead',  80],
            ['internet',        'إنترنت واتصالات',       'overhead',  90],
            ['packaging',       'مواد تغليف',            'operating', 100],
            ['printing',        'طباعة ووصولات',         'operating', 110],
            ['external_ship',   'شحن خارجي بين الفروع',  'operating', 120],
            ['customs',         'رسوم وغرامات',          'operating', 130],
            ['damaged_goods',   'تعويض بضاعة تالفة',     'operating', 140],
            ['marketing',       'تسويق وإعلان',          'overhead',  150],
            ['other',           'مصروف آخر',             'overhead',  999],
        ];

        foreach ($categories as [$code, $name, $group, $sort]) {
            ExpenseCategory::updateOrCreate(
                ['company_id' => null, 'code' => $code],
                ['name_ar' => $name, 'group' => $group, 'sort_order' => $sort, 'is_active' => true],
            );
        }
    }
}
