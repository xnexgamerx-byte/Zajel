<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قاعدة تسعير واحدة: من محافظة -> إلى محافظة/منطقة، ضمن مدى وزن.
 * القاعدة الأكثر تحديداً تفوز (منطقة > محافظة > null = يشمل الكل).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();

            $table->foreignId('from_governorate_id')->nullable()->constrained('governorates')->nullOnDelete();
            $table->foreignId('to_governorate_id')->nullable()->constrained('governorates')->nullOnDelete();
            $table->foreignId('to_city_id')->nullable()->constrained('cities')->nullOnDelete();

            $table->unsignedInteger('weight_from_grams')->default(0);
            $table->unsignedInteger('weight_to_grams')->default(1000000);

            $table->bigInteger('delivery_fee')->default(0);      // أجرة التوصيل
            $table->bigInteger('return_fee')->default(0);        // أجرة الراجع
            $table->bigInteger('replacement_fee')->default(0);   // أجرة الاستبدال
            $table->bigInteger('extra_kg_fee')->default(0);      // لكل كغم زائد
            $table->bigInteger('cod_fee_flat')->default(0);      // عمولة تحصيل ثابتة
            $table->decimal('cod_fee_percent', 5, 2)->default(0);

            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['price_list_id', 'to_governorate_id', 'to_city_id'], 'plr_lookup_idx');
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_rules');
    }
};
