<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حصص الاستلام واعتراضاتها.
 *
 * مندوب الاستلام يأخذ حصّة عن كل طرد يجمعه من التاجر، وله أن يعترض على
 * احتسابها. نظام حوافز بآلية تظلّم — وهو ما يمنع الخلاف الشفويّ الذي
 * لا أثر له: «جمعتُ أربعين طرداً واحتسبتم لي ثلاثين».
 *
 * والصفّ هنا هو الأساس المكتوب للحصّة: كم طرداً، وبكم، ومتى، ومن أي
 * طلب استلام. والحركة في الدفتر تشير إليه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable();
            $table->foreignId('courier_id');
            $table->foreignId('pickup_request_id')->nullable();

            $table->unsignedInteger('shipments_count');
            $table->bigInteger('rate');          // الحصّة عن الطرد الواحد وقت الاحتساب
            $table->bigInteger('amount');        // المجمَّد: العدد × الحصّة

            $table->string('status', 12)->default('accrued'); // accrued | objected | adjusted | rejected
            $table->string('objection_reason', 255)->nullable();
            $table->unsignedInteger('claimed_count')->nullable();   // ما يقوله المندوب إنه جمعه
            $table->timestamp('objected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable();
            $table->string('resolution_note', 255)->nullable();
            $table->bigInteger('adjustment')->default(0);   // الفرق المُقَرّ بعد الاعتراض

            $table->timestamps();

            $table->index(['company_id', 'courier_id', 'status'], 'ps_courier_idx');
            $table->index(['company_id', 'status', 'objected_at'], 'ps_objection_idx');
            $table->unique(['company_id', 'pickup_request_id'], 'ps_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_shares');
    }
};
