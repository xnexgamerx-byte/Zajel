<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أسباب الفشل مصنّفة. "ما رد" و"العنوان غلط" و"رفض الاستلام" ليست شيئاً واحداً:
 * الأول مشكلة زبون، والثاني مشكلة تاجر، والثالث مشكلة بضاعة.
 * بلا تصنيف لا يمكن إخبار التاجر لماذا 20% من شحناته ترجع.
 *
 * company_id = null  ->  سبب افتراضي متاح لكل الشركات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failure_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name_ar', 160);
            $table->string('name_en', 160)->nullable();

            // من يتحمّل المسؤولية — أساس تقرير "لماذا ترجع شحناتك"
            $table->enum('category', ['customer', 'address', 'merchandise', 'courier', 'merchant', 'external'])
                ->default('customer');

            $table->boolean('requires_note')->default(false);
            $table->boolean('requires_photo')->default(false);
            $table->boolean('counts_as_attempt')->default(true);
            $table->boolean('allows_reschedule')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failure_reasons');
    }
};
