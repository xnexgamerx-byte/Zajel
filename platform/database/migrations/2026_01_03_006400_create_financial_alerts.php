<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التنبيهات المالية (docs/plan/68 §١٢): يولّدها فحصٌ كل ساعة وأحداثٌ فورية، ولكلٍّ مفتاحٌ يمنع
 * تكراره. وللتنبيه خطورته وفرعه ومندوبه أو تاجره ومبلغه ومسؤوله ومهلته وحاله، وسجلّ ما جرى عليه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('key', 120);
            // info | warning | critical
            $table->string('severity', 10);
            // open | in_progress | resolved | ignored
            $table->string('status', 12)->default('open');
            $table->string('title', 160);
            $table->string('details', 500)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->bigInteger('amount')->nullable();
            // من يعالجه: صلاحيةٌ (دور)، ومن تولّاه بعينه
            $table->string('assignee_ability', 40)->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // زال سببه في فحصٍ ما: إن عاد بعده فهو حدوثٌ جديد
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'key'], 'fa_key_unique');
            $table->index(['company_id', 'status', 'severity'], 'fa_status_idx');
            $table->index(['company_id', 'type', 'status'], 'fa_type_idx');
        });

        Schema::create('financial_alert_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 120)->nullable();
            // opened | escalated | taken | resolved | ignored | reopened | cleared
            $table->string('action', 12);
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'financial_alert_id'], 'faa_alert_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_alert_actions');
        Schema::dropIfExists('financial_alerts');
    }
};
