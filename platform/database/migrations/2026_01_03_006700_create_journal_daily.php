<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الملخّص اليومي للدفتر (docs/plan/68 §١٨): مدين ودائن كل حسابٍ لكل فرعٍ في كل يوم. يُكتب مع القيد
 * في معاملته (Journal::post)، فالميزان واللوحة وفحوص الإقفال تقرأ آلاف الصفوف لا مليون سطر —
 * قيست على ١٦٥ ألف شحنة: ميزان المراجعة ٨ ثوانٍ واللوحة ٤ قبله.
 * قيد الإقفال منفصلٌ بعلامته (closing): الميزان يراه، واللوحة لا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('account', 8);
            // صفرٌ لما لا فرع له: عمودٌ في المفتاح الفريد لا يكون فارغاً
            $table->unsignedBigInteger('branch_id')->default(0);
            $table->boolean('closing')->default(false);
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);

            $table->unique(['company_id', 'entry_date', 'account', 'branch_id', 'closing'], 'jd_key');
            $table->index(['company_id', 'account', 'entry_date'], 'jd_account_idx');
        });

        // قيود حدثٍ بعينه (الإقفال، الافتتاحي): كانت تُبحث بمسح القيود كلّها
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(['company_id', 'event'], 'je_event_idx');
        });

        DB::statement("INSERT INTO journal_daily (company_id, entry_date, account, branch_id, closing, debit, credit)
            SELECT l.company_id, l.entry_date, l.account, coalesce(l.branch_id, 0),
                   case when e.event = 'period_close' then 1 else 0 end, sum(l.debit), sum(l.credit)
            FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id
            GROUP BY l.company_id, l.entry_date, l.account, coalesce(l.branch_id, 0), case when e.event = 'period_close' then 1 else 0 end");
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('je_event_idx');
        });
        Schema::dropIfExists('journal_daily');
    }
};
