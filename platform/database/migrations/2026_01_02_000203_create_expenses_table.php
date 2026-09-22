<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المصروفات. مصروف مسجَّل ومصروف مدفوع ليسا واحداً: الأوّل التزام على
 * الشركة، والثاني نقد غادر القاصة. فصلُهما هو ما يجعل رصيد الصندوق
 * يطابق الدرج، ويجعل «كم علينا هذا الشهر؟» سؤالاً له جواب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable();
            $table->foreignId('expense_category_id');

            $table->string('number', 30);
            $table->bigInteger('amount');
            $table->date('spent_on');
            $table->string('description', 255);
            $table->string('payee', 120)->nullable();       // لمن دُفع
            $table->string('reference', 60)->nullable();     // رقم وصل خارجي

            $table->string('status', 12)->default('recorded'); // recorded | paid | cancelled
            $table->foreignId('cash_box_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'spent_on'], 'ex_status_idx');
            $table->index(['company_id', 'expense_category_id', 'spent_on'], 'ex_cat_idx');
            $table->index(['company_id', 'branch_id', 'spent_on'], 'ex_branch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
