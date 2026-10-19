<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * وجبات التحصيل، الإصدار الثاني (docs/plan/68 §٧): حالات الوجبة، والوجبة أولاً (تُقفَل كشوف
 * مناديبها من داخلها عند استلام نقدهم)، ومطابقة نقد كل مندوب ومعالجة فرقه، والمرفقات.
 *
 * الوجبات المعتمدة قبل هذا الإصدار تبقى كما هي: فروقها موثّقةٌ بلا حركة (posted_at فارغ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_batches', function (Blueprint $table) {
            // draft | awaiting_cash | awaiting_reconciliation | discrepancy | approved | cancelled
            $table->string('status', 24)->default('draft')->change();
            // صندوق الوجبة: يُقبض فيه نقد ما تُقفَل كشوفه منها، وتُسجَّل فيه زيادتها وتحصيل العجز
            $table->foreignId('cash_box_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
            $table->bigInteger('realized_fees')->default(0)->after('realized_profit');
            $table->timestamp('cancelled_at')->nullable()->after('approved_by_user_id');
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable()->after('cancelled_by_user_id');
        });

        Schema::table('collection_batch_items', function (Blueprint $table) {
            // pending_cash | received | short | over — والعجز يُعالَج: courier_debt | written_off
            $table->string('status', 16)->default('received')->after('kind');
            $table->string('resolution', 16)->nullable()->after('status');
            $table->string('resolution_reason', 255)->nullable()->after('resolution');
            $table->timestamp('counted_at')->nullable();
            $table->foreignId('counted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // متى كُتبت حركات فرقه (العجز من الصندوق إلى ذمّة المندوب، الزيادة، التحصيل)
            $table->timestamp('posted_at')->nullable();
            $table->bigInteger('realized_fees')->default(0);
        });

        Schema::create('collection_batch_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_batch_id')->constrained()->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('name', 160);
            $table->string('mime', 80)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        // ما قبل الإصدار: حال كل سطرٍ من فرقه، والمسودّات تصير «بانتظار المطابقة» أو «فيها فروقات»
        DB::table('collection_batch_items')->where('kind', 'settlement')->whereColumn('received_cash', '<', 'expected_cash')->update(['status' => 'short']);
        DB::table('collection_batch_items')->where('kind', 'settlement')->whereColumn('received_cash', '>', 'expected_cash')->update(['status' => 'over']);

        $open = DB::table('collection_batches')->where('status', 'draft');
        (clone $open)->whereExists(fn ($q) => $q->selectRaw('1')->from('collection_batch_items as i')
            ->whereColumn('i.collection_batch_id', 'collection_batches.id')->where('i.status', 'short'))
            ->update(['status' => 'discrepancy']);
        (clone $open)->whereExists(fn ($q) => $q->selectRaw('1')->from('collection_batch_items as i')
            ->whereColumn('i.collection_batch_id', 'collection_batches.id'))
            ->update(['status' => 'awaiting_reconciliation']);
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_batch_files');

        Schema::table('collection_batch_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('counted_by_user_id');
        });
        Schema::table('collection_batch_items', function (Blueprint $table) {
            $table->dropColumn(['status', 'resolution', 'resolution_reason', 'counted_at', 'posted_at', 'realized_fees']);
        });

        Schema::table('collection_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_box_id');
            $table->dropConstrainedForeignId('cancelled_by_user_id');
        });
        Schema::table('collection_batches', function (Blueprint $table) {
            // طول الحالة يبقى ٢٤ — أطول من حاجة الإصدار الأول ولا يضرّه
            $table->dropColumn(['realized_fees', 'cancelled_at', 'cancel_reason']);
        });
    }
};
