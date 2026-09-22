<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أبواب المصروف مصنّفة لا نصّ حرّ — للسبب نفسه الذي صُنّفت لأجله أسباب
 * الفشل: «أين ذهب المال هذا الشهر؟» سؤال لا يُجيبه عمود نصّي.
 * company_id = null تعني باباً افتراضياً متاحاً لكل الشركات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('code', 40);
            $table->string('name_ar', 120);
            $table->string('group', 20)->default('operating'); // operating | staff | vehicles | overhead
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active'], 'ec_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
