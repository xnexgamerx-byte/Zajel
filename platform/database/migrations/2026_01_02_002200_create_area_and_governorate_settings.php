<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التسعير بالمركز والأطراف، كما في «إعدادات المحافظة» و«المناطق» في المعتاد.
 *
 * المناطق والمحافظات مرجعٌ مشترك بين الشركات، أمّا ما تعنيه لكلّ شركة
 * فإعداداتها هي: أيّ منطقةٍ «طرفية» عندها وبأيّ أجرة، وأيّ محافظةٍ تشحن
 * إليها وبأيّ ترتيب، وكم يأخذ المندوب إلى مركزها وإلى أطرافها.
 *
 * وفي التسعيرة نفسها مبلغان للمحافظة: للمركز وللأقضية والأطراف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_peripheral')->default(false);
            // «أجرة النقل الخاصّة بالمنطقة»: تغلب أجرة محافظتها في التسعيرة الافتراضية
            $table->bigInteger('delivery_fee')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'city_id']);
        });

        Schema::create('governorate_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('governorate_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->nullable();
            // «أجرة المندوب» و«أجرة المندوب للأقضية»: لمن لا أجرة له في بطاقته
            $table->bigInteger('courier_fee')->nullable();
            $table->bigInteger('courier_fee_peripheral')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'governorate_id']);
        });

        Schema::table('price_list_rules', function (Blueprint $table) {
            // «مبلغ الشحن للأقضية»: فارغاً تُسعَّر الأطراف كالمركز
            $table->bigInteger('peripheral_fee')->nullable()->after('delivery_fee');
        });
    }

    public function down(): void
    {
        Schema::table('price_list_rules', function (Blueprint $table) {
            $table->dropColumn('peripheral_fee');
        });

        Schema::dropIfExists('governorate_settings');
        Schema::dropIfExists('city_settings');
    }
};
