<?php

use App\Support\AreaImport;
use Illuminate\Database\Migrations\Migration;

/**
 * ثمانٍ من مناطق بغداد التي أضافها 2026_01_03_000100 كانت موجودةً قبلها بـ«حي» أو
 * «منطقة» أو بدونهما: «الرشاد» و«حي الرشاد»، «منطقة الغدير» و«الغدير». صارت المقارنة
 * تتجاوزهما (Arabic::looseFold) فخرجت من القائمة؛ وخادمٌ حدّث قبل ذلك تُضمّ فيه كلٌّ
 * منها إلى الأقدم، وكل ما أشار إليها يشير إليه. وخادمٌ لم يحدّث بعدُ لا يجدها هنا.
 */
return new class extends Migration
{
    private const BAGHDAD = [
        'رساله الثالثة', 'حي الاعلام', 'معالف', 'حي الكيلاني', 'حي القاهره', 'البنوك', 'منطقة الغدير', 'الرشاد',
    ];

    public function up(): void
    {
        AreaImport::mergeDuplicates('BGD', self::BAGHDAD);
    }

    /** لا رجوع: شحناتٌ ومناديب صارت على المنطقة الباقية ولا يُعرف أيّها كان على المضمومة */
    public function down(): void {}
};
