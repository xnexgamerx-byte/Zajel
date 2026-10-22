<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «من فتح الطلب يعالجه» (docs/plan/73): تذكرة تغيير المبلغ، وشحنة المعالجة، ومحادثة التاجر —
 * تُحجز لموظّفةٍ واحدة ما دامت تعمل عليها، فلا تعالجها زميلتها في الوقت نفسه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 20); // ticket | processing | conversation
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('claimed_at');
            // آخر ما عملت عليه: بعد مهلةٍ بلا عمل يُفلت وحده
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->unique(['company_id', 'subject', 'subject_id'], 'wc_subject_unique');
            $table->index(['company_id', 'user_id'], 'wc_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_claims');
    }
};
