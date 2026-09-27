<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلبات التاجر ودفعات الراجع، كما في المعتاد:
 *
 * «طلبات حساب من العملاء» و«طلبات كشف راجع»: التاجر يطلب من بوابته برقمٍ
 * (REQ-…) بدل أن يتّصل، والشركة ترى ما ينتظرها ومن عالجه ومتى.
 *
 * و«دفعات الراجع»: كل تسليمٍ لرواجع تاجرٍ إيصالٌ برقم — من المخزن أو مع مندوب
 * الاستلام — ويُطبع، ويُؤكَّد استلامه الفعليّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('via', 20); // store | pickup_courier
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('shipments_count')->default(0);
            $table->unsignedBigInteger('return_fees_total')->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamp('handed_at');
            $table->foreignId('handed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // الاستلام الفعليّ عند التاجر: فوراً من المخزن، وبتأكيده إن حمله المندوب
            $table->timestamp('received_at')->nullable();
            $table->string('received_by', 120)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'merchant_id', 'handed_at'], 'rb_merchant_idx');
            $table->index(['company_id', 'received_at'], 'rb_received_idx');
        });

        Schema::create('merchant_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // payment | returns
            $table->string('number', 30);
            $table->string('payout_method', 20)->nullable();
            $table->boolean('via_pickup_courier')->default(false);
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->default('open'); // open | handled | cancelled
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('merchant_settlement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('return_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'type', 'status', 'created_at'], 'mr_queue_idx');
            $table->index(['company_id', 'merchant_id', 'type', 'status'], 'mr_merchant_idx');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('return_batch_id')->nullable()->after('merchant_settlement_id');
            $table->index(['company_id', 'return_batch_id'], 'sh_return_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_return_batch_idx');
            $table->dropColumn('return_batch_id');
        });

        Schema::dropIfExists('merchant_requests');
        Schema::dropIfExists('return_batches');
    }
};
