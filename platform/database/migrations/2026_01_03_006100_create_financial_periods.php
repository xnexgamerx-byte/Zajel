<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إقفال الفترات (docs/plan/68 §٩): الفترة المحاسبية للشركة كلّها، تُعَدّ ثم تُعتمد، وتُحفظ
 * أرقامها وفحوصها واستثناءاتها وتقريرها بنسخه. وبعد إقفالها لا يُكتب قيدٌ بتاريخها: ما يمسّها
 * يُكتب بتاريخ الفترة المفتوحة ويُشير إليها (adjusts_period_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->date('from_date');
            $table->date('to_date');
            // pending_approval | closed
            $table->string('status', 20)->default('pending_approval');
            $table->foreignId('prepared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('figures')->nullable();
            $table->json('checks')->nullable();
            $table->json('exceptions')->nullable();
            $table->foreignId('closing_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number'], 'fp_number_unique');
            $table->unique(['company_id', 'from_date', 'to_date'], 'fp_range_unique');
            $table->index(['company_id', 'status', 'from_date'], 'fp_status_idx');
        });

        Schema::create('financial_period_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->json('figures');
            $table->json('adjustments')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->string('reason', 255)->nullable();

            $table->unique(['financial_period_id', 'version'], 'fpr_version_unique');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('adjusts_period_id')->nullable()->after('source_id')->constrained('financial_periods')->nullOnDelete();
        });

        // إعداد الإقفال لمن يُعِدّ العوائد (المحاسب)؛ واعتماده لصاحب الشركة ومن يُمنح
        foreach (DB::table('ranks')->get(['id', 'abilities']) as $rank) {
            $abilities = json_decode((string) $rank->abilities, true);

            if (is_array($abilities) && in_array('money.profits', $abilities, true) && ! in_array('periods.prepare', $abilities, true)) {
                DB::table('ranks')->where('id', $rank->id)->update(['abilities' => json_encode([...$abilities, 'periods.prepare'])]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('ranks')->get(['id', 'abilities']) as $rank) {
            $abilities = json_decode((string) $rank->abilities, true);

            if (is_array($abilities) && array_intersect(['periods.prepare', 'periods.close'], $abilities)) {
                DB::table('ranks')->where('id', $rank->id)
                    ->update(['abilities' => json_encode(array_values(array_diff($abilities, ['periods.prepare', 'periods.close'])))]);
            }
        }
        DB::table('user_grants')->whereIn('ability', ['periods.prepare', 'periods.close'])->delete();

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjusts_period_id');
        });
        Schema::dropIfExists('financial_period_reports');
        Schema::dropIfExists('financial_periods');
    }
};
