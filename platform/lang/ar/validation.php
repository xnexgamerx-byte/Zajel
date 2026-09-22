<?php

/**
 * رسائل التحقّق بالعربية.
 *
 * المستخدم عراقي والواجهة عربية، فرسالة مثل "The الهاتف field format is
 * invalid" تخلط اللغتين وتربك أكثر ممّا تشرح.
 */
return [
    'accepted'    => 'يجب قبول :attribute.',
    'after'       => 'يجب أن يكون :attribute بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute بتاريخ :date أو بعده.',
    'array'       => 'يجب أن يكون :attribute قائمة.',
    'before'      => 'يجب أن يكون :attribute قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute بتاريخ :date أو قبله.',
    'between'     => [
        'array'   => 'عناصر :attribute يجب أن تكون بين :min و :max.',
        'numeric' => 'يجب أن يكون :attribute بين :min و :max.',
        'string'  => 'طول :attribute يجب أن يكون بين :min و :max.',
    ],
    'boolean'     => 'حقل :attribute يجب أن يكون نعم أو لا.',
    'confirmed'   => 'تأكيد :attribute غير مطابق.',
    'date'        => ':attribute ليس تاريخاً صحيحاً.',
    'date_format' => 'صيغة :attribute لا تطابق :format.',
    'different'   => 'يجب أن يختلف :attribute عن :other.',
    'digits'      => 'عدد أرقام :attribute يجب أن يكون :digits.',
    'digits_between' => 'عدد أرقام :attribute يجب أن يكون بين :min و :max.',
    'email'       => 'يجب أن يكون :attribute بريداً إلكترونياً صحيحاً.',
    'exists'      => ':attribute المحدَّد غير موجود.',
    'file'        => 'يجب أن يكون :attribute ملفاً.',
    'filled'      => 'حقل :attribute مطلوب.',
    'gt'          => [
        'numeric' => 'يجب أن يكون :attribute أكبر من :value.',
        'string'  => 'طول :attribute يجب أن يتجاوز :value.',
    ],
    'gte'         => ['numeric' => 'يجب أن يكون :attribute :value أو أكبر.'],
    'image'       => 'يجب أن يكون :attribute صورة.',
    'in'          => ':attribute المحدَّد غير صالح.',
    'integer'     => 'يجب أن يكون :attribute رقماً صحيحاً.',
    'lt'          => ['numeric' => 'يجب أن يكون :attribute أصغر من :value.'],
    'lte'         => ['numeric' => 'يجب أن يكون :attribute :value أو أصغر.'],
    // الصياغة تتجنّب تمييز العدد: "3 أحرف" و"11 حرفاً" قاعدتان مختلفتان،
    // و«الحد الأدنى/الأعلى» يصحّ مع كل عدد.
    'max'         => [
        'array'   => 'الحد الأعلى لعناصر :attribute هو :max.',
        'file'    => 'الحد الأعلى لحجم :attribute هو :max كيلوبايت.',
        'numeric' => 'الحد الأعلى لـ:attribute هو :max.',
        'string'  => 'الحد الأعلى لطول :attribute هو :max.',
    ],
    'mimes'       => 'يجب أن يكون :attribute ملفاً من نوع: :values.',
    'min'         => [
        'array'   => 'الحد الأدنى لعناصر :attribute هو :min.',
        'numeric' => 'الحد الأدنى لـ:attribute هو :min.',
        'string'  => 'الحد الأدنى لطول :attribute هو :min.',
    ],
    'not_in'      => ':attribute المحدَّد غير صالح.',
    'numeric'     => 'يجب أن يكون :attribute رقماً.',
    'present'     => 'يجب إرسال حقل :attribute.',
    'regex'       => 'صيغة :attribute غير صحيحة.',
    'required'    => 'حقل :attribute مطلوب.',
    'required_if' => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_with' => 'حقل :attribute مطلوب مع :values.',
    'same'        => 'يجب أن يتطابق :attribute مع :other.',
    'size'        => [
        'numeric' => 'يجب أن يكون :attribute :size.',
        'string'  => 'يجب أن يكون :attribute :size حرفاً.',
    ],
    'string'      => 'يجب أن يكون :attribute نصّاً.',
    'unique'      => ':attribute مستعمل من قبل.',
    'uploaded'    => 'فشل رفع :attribute.',
    'url'         => 'صيغة :attribute غير صحيحة.',

    'custom'     => [],
    'attributes' => [],
];
