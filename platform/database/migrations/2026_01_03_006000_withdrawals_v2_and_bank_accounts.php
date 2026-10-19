<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة الرابعة (docs/plan/68 §١٠ و§١١):
 *
 * - سحب الأرباح بمساره: طلبٌ ← معتمد ← مدفوع، أو ملغى قبل الدفع، أو معكوسٌ في يومه بسبب.
 *   والمتاح قبل الدفع وبعده محفوظان على السحب، ومفتاح طلبٍ فريد يمنع الدفع المكرّر.
 *   وما سُجِّل قبله كان «done» مدفوعاً، و«cancelled» إلغاءً لمدفوع: هو «معكوس» الآن.
 * - الحسابات المصرفية نوعٌ من الصناديق (ق١١): اسم البنك ووصف الحساب، بلا رقمه الكامل.
 * - صلاحية السحب الواحدة (money.withdraw) تصير طلباً واعتماداً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profit_withdrawals', function (Blueprint $table) {
            // requested | approved | paid | cancelled | reversed
            $table->string('status', 12)->default('requested')->change();
            $table->foreignId('approved_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            $table->foreignId('paid_by_user_id')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('paid_by_user_id');
            $table->bigInteger('available_before')->nullable()->after('paid_at');
            $table->bigInteger('available_after')->nullable()->after('available_before');
            $table->string('idempotency_key', 64)->nullable()->after('available_after');

            $table->unique(['company_id', 'idempotency_key'], 'profit_wd_key_unique');
        });

        DB::table('profit_withdrawals')->where('status', 'done')->update([
            'status'              => 'paid',
            'approved_by_user_id' => DB::raw('created_by_user_id'),
            'approved_at'         => DB::raw('created_at'),
            'paid_by_user_id'     => DB::raw('created_by_user_id'),
            'paid_at'             => DB::raw('created_at'),
        ]);
        DB::table('profit_withdrawals')->where('status', 'cancelled')->update([
            'status'          => 'reversed',
            'paid_by_user_id' => DB::raw('created_by_user_id'),
            'paid_at'         => DB::raw('created_at'),
        ]);

        Schema::table('cash_boxes', function (Blueprint $table) {
            $table->string('bank_name', 80)->nullable()->after('type');
            // وصفٌ يعرف به الموظّف الحساب («الحساب الجاري — آخره ٤٢١٧»)، لا رقمه الكامل
            $table->string('account_label', 60)->nullable()->after('bank_name');
        });

        $swap = fn (array $abilities) => array_values(array_unique([
            ...array_diff($abilities, ['money.withdraw']), 'withdrawals.request', 'withdrawals.approve',
        ]));

        foreach (DB::table('ranks')->get(['id', 'abilities']) as $rank) {
            $abilities = json_decode((string) $rank->abilities, true);

            if (is_array($abilities) && in_array('money.withdraw', $abilities, true)) {
                DB::table('ranks')->where('id', $rank->id)->update(['abilities' => json_encode($swap($abilities))]);
            }
        }

        foreach (DB::table('user_grants')->where('ability', 'money.withdraw')->get() as $grant) {
            foreach (['withdrawals.request', 'withdrawals.approve'] as $ability) {
                DB::table('user_grants')->updateOrInsert(
                    ['user_id' => $grant->user_id, 'ability' => $ability],
                    ['company_id' => $grant->company_id, 'note' => $grant->note, 'granted_by_user_id' => $grant->granted_by_user_id,
                        'granted_by_name' => $grant->granted_by_name, 'created_at' => $grant->created_at, 'updated_at' => now()],
                );
            }
        }
        DB::table('user_grants')->where('ability', 'money.withdraw')->delete();
    }

    public function down(): void
    {
        foreach (DB::table('ranks')->get(['id', 'abilities']) as $rank) {
            $abilities = json_decode((string) $rank->abilities, true);

            if (is_array($abilities) && array_intersect(['withdrawals.request', 'withdrawals.approve'], $abilities)) {
                DB::table('ranks')->where('id', $rank->id)->update(['abilities' => json_encode(array_values(array_unique([
                    ...array_diff($abilities, ['withdrawals.request', 'withdrawals.approve']), 'money.withdraw',
                ])))]);
            }
        }

        foreach (DB::table('user_grants')->where('ability', 'withdrawals.approve')->get() as $grant) {
            DB::table('user_grants')->updateOrInsert(['user_id' => $grant->user_id, 'ability' => 'money.withdraw'],
                ['company_id' => $grant->company_id, 'created_at' => $grant->created_at, 'updated_at' => now()]);
        }
        DB::table('user_grants')->whereIn('ability', ['withdrawals.request', 'withdrawals.approve'])->delete();

        Schema::table('cash_boxes', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'account_label']);
        });

        DB::table('profit_withdrawals')->where('status', 'paid')->update(['status' => 'done']);
        DB::table('profit_withdrawals')->whereIn('status', ['reversed', 'cancelled', 'requested', 'approved'])->update(['status' => 'cancelled']);

        Schema::table('profit_withdrawals', function (Blueprint $table) {
            $table->dropUnique('profit_wd_key_unique');
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropConstrainedForeignId('paid_by_user_id');
        });
        Schema::table('profit_withdrawals', function (Blueprint $table) {
            $table->dropColumn(['approved_at', 'paid_at', 'available_before', 'available_after', 'idempotency_key']);
        });
    }
};
