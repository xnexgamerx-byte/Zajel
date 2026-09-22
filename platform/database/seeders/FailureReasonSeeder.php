<?php

namespace Database\Seeders;

use App\Models\FailureReason;
use Illuminate\Database\Seeder;

/**
 * أسباب الفشل الافتراضية (company_id = null) — متاحة لكل الشركات.
 * التصنيف ليس تجميلاً: هو ما يسمح بإخبار التاجر أنّ 18% من شحناته
 * ترجع بسبب "الرقم خطأ" لا بسبب كسل المندوب.
 */
class FailureReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            ['no_answer',            'لا يرد على الهاتف',            'customer',    false, true,  true],
            ['phone_off',            'الهاتف مغلق',                  'customer',    false, true,  true],
            ['customer_not_home',    'الزبون غير موجود بالعنوان',    'customer',    false, true,  true],
            ['customer_postponed',   'الزبون طلب التأجيل',           'customer',    true,  false, true],
            ['customer_refused',     'الزبون رفض الاستلام',          'customer',    true,  true,  false],
            ['no_money',             'الزبون لا يملك المبلغ',        'customer',    false, true,  true],
            ['wrong_number',         'رقم الهاتف خطأ',               'address',     false, true,  false],
            ['address_not_found',    'العنوان غير صحيح',             'address',     true,  true,  true],
            ['area_not_covered',     'المنطقة خارج التغطية',         'address',     false, false, false],
            ['partial_refusal',      'رفض جزءاً من الطلب',           'merchandise', true,  true,  false],
            ['damaged_item',         'البضاعة تالفة',                'merchandise', true,  true,  false],
            ['wrong_item',           'البضاعة غير مطابقة',           'merchant',    true,  true,  false],
            ['price_dispute',        'خلاف على السعر',               'merchant',    true,  true,  true],
            ['duplicate_order',      'طلب مكرر',                     'merchant',    false, false, false],
            ['courier_ran_out_time', 'لم يسع وقت المندوب',           'courier',     false, false, true],
            ['road_closed',          'الطريق مغلق / ظرف أمني',       'external',    true,  false, true],
            ['weather',              'حالة جوية',                    'external',    false, false, true],
        ];

        foreach ($reasons as $i => [$code, $name, $category, $note, $attempt, $reschedule]) {
            FailureReason::updateOrCreate(
                ['company_id' => null, 'code' => $code],
                [
                    'name_ar'           => $name,
                    'category'          => $category,
                    'requires_note'     => $note,
                    'counts_as_attempt' => $attempt,
                    'allows_reschedule' => $reschedule,
                    'is_active'         => true,
                    'sort_order'        => $i,
                ],
            );
        }
    }
}
