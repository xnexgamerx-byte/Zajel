<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حركات الصندوق — مُلحَق لا يُعدَّل ولا يُحذف منه صفّ، كدفتر الحركات.
 * كل صفّ يحمل رصيد الصندوق بعده، فجردُ يومٍ ماضٍ لا يحتاج إعادة حساب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_box_id');
            $table->foreignId('branch_id')->nullable();

            $table->string('direction', 4);        // in | out
            $table->string('category', 40);        // courier_handover | merchant_payout | expense
                                                   // transfer_in | transfer_out | opening | adjustment
            $table->bigInteger('amount');          // موجب دائماً — الاتجاه في direction
            $table->bigInteger('balance_after');

            $table->string('reference_type', 40)->nullable();  // courier_settlement | merchant_settlement | expense
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('counterpart_box_id')->nullable(); // في المناقلة بين صندوقين

            $table->string('description', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'cash_box_id', 'id'], 'cm_box_idx');
            $table->index(['company_id', 'created_at'], 'cm_time_idx');
            $table->index(['reference_type', 'reference_id'], 'cm_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
