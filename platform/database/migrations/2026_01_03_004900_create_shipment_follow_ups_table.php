<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «بدأت المتابعة» في شحنات لم تُسلَّم (docs/plan/53): موظّفٌ اتّصل بالزبون فكتب اسمه عليها، فيعرف
 * زملاؤه أنها بيد أحد — وملاحظته له وحده. سطرٌ لكلّ موظّفٍ في الشحنة، يبدأ من جديد إن تعثّرت ثانية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('started_at');
            $table->timestamps();

            $table->unique(['shipment_id', 'user_id']);
            $table->index(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_follow_ups');
    }
};
