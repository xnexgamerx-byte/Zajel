<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * القاصة — النقد الذي في الدرج فعلاً.
 *
 * دفتر الحركات يُجيب «مَن له ومَن عليه»، والقاصة تُجيب سؤالاً آخر لا
 * يُجيبه: كم ديناراً يجب أن يكون في الخزنة الآن؟ الخلط بينهما هو سبب
 * أن تُقفَل شركة يومها وهي لا تعرف أين ذهب فرق المليونين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable();

            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('type', 20)->default('main');   // main | branch | petty
            $table->bigInteger('balance')->default(0);     // تسريع قراءة — الحقيقة في cash_movements
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'branch_id', 'is_active'], 'cb_branch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_boxes');
    }
};
