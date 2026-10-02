<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أجرة التوصيل مدفوعةً مقدّماً (docs/plan/22 §٣).
 *
 * التاجر الذي «يُحاسَب مقدّماً» يدفع أجور شحناته حين يُرسلها، فلا تُخصم من
 * مبلغها عند التسليم. الشحنة تُعلَّم «مدفوعة التوصيل مقدّماً»، وما يُقبض عنها
 * فعلاً يُكتب في prepaid_amount بإيصالٍ في prepaid_receipts — ويدخل مستحقّ
 * التاجر عند التسليم أو الرجوع، فتدفعه تسويته كما تدفع غيره.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->boolean('prepaid_billing')->default(false)->after('hold_for_review');
        });

        Schema::create('prepaid_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_box_id')->nullable()->constrained('cash_boxes')->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->unsignedInteger('shipments_count');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'merchant_id', 'created_at'], 'ppr_merchant_idx');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->boolean('fee_prepaid')->default(false)->after('fees_paid_by');
            $table->unsignedBigInteger('prepaid_amount')->default(0)->after('fee_prepaid');
            $table->foreignId('prepaid_receipt_id')->nullable()->after('prepaid_amount')
                ->constrained('prepaid_receipts')->nullOnDelete();

            // «استلام أجور مدفوعة مقدّماً»: ما عُلِّم ولم يُقبض بعد، لكل تاجر
            $table->index(['company_id', 'merchant_id', 'fee_prepaid', 'prepaid_receipt_id'], 'sh_prepaid_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_prepaid_idx');
            $table->dropConstrainedForeignId('prepaid_receipt_id');
            $table->dropColumn(['fee_prepaid', 'prepaid_amount']);
        });

        Schema::dropIfExists('prepaid_receipts');

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('prepaid_billing');
        });
    }
};
