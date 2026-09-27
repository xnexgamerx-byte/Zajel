<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المال كما في المعتاد:
 *
 * «صناديق الدفع»: صندوقٌ لكل موظّف يقبض بيده، يُسلِّم منه للقاصة.
 *
 * «استلام المبالغ المسدّدة من الفروع»: فرعٌ يسدّد لآخر ما جمعه من شحنات
 * تجّاره، والآخر يستلمه بمبلغه الفعليّ — والفرق بملاحظته لا بصمت.
 *
 * «الموقف المالي» ولقطاته: الأرقام كما كانت يوم التُقطت، لا كما هي الآن.
 *
 * والمصروف: «رقم أمر الصرف» و«القسم»، وأرشفةٌ بالتحديد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_boxes', function (Blueprint $table) {
            // صندوق موظّف: ما قبضه بيده يدخله، ومنه يُسلَّم للقاصة
            $table->foreignId('user_id')->nullable()->after('branch_id')->constrained('users')->nullOnDelete();
        });

        Schema::create('branch_remittances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('from_branch_id')->constrained('branches');
            $table->foreignId('to_branch_id')->constrained('branches');
            $table->unsignedBigInteger('amount');
            $table->foreignId('from_box_id')->nullable()->constrained('cash_boxes')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('sent_at');
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('sent'); // sent | received
            $table->unsignedBigInteger('received_amount')->nullable();
            $table->foreignId('to_box_id')->nullable()->constrained('cash_boxes')->nullOnDelete();
            $table->string('difference_note', 255)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'to_branch_id', 'status'], 'br_inbox_idx');
            $table->index(['company_id', 'from_branch_id', 'to_branch_id'], 'br_pair_idx');
        });

        Schema::create('financial_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->timestamp('taken_at');
            // فارغاً: التقطها الجدول الليليّ
            $table->foreignId('taken_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('figures');
            $table->bigInteger('total');
            $table->timestamps();

            $table->index(['company_id', 'taken_at']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('order_number', 40)->nullable()->after('reference');
            $table->string('department', 80)->nullable()->after('order_number');
            $table->timestamp('archived_at')->nullable()->after('cancel_reason');
            $table->foreignId('archived_by_user_id')->nullable()->after('archived_at');
            $table->index(['company_id', 'archived_at', 'spent_on'], 'ex_archive_idx');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('ex_archive_idx');
            $table->dropColumn(['order_number', 'department', 'archived_at', 'archived_by_user_id']);
        });

        Schema::dropIfExists('financial_snapshots');
        Schema::dropIfExists('branch_remittances');

        Schema::table('cash_boxes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
