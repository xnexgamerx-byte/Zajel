<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «العوائد»: وجبات التحصيل والأرباح وسحب الأرباح.
 *
 * الوجبة (COL-0001) تجمع كشوف مناديب مُقفَلة في مدّةٍ لفرع، فتفصل ما استلمه المندوب إلى
 * أموال التجّار وأجور التوصيل (إيراد الشركة) وصافي الربح بعد العمولات والتكاليف. وكلّ كشفٍ
 * يدخل وجبةً واحدة (الفهرس الفريد)، وكلّ مصروفٍ كذلك — وكلّ شحنةٍ في كشفٍ واحد أصلاً.
 * وتحصيل ما نقص من مندوبٍ في وجبةٍ سابقة سطرٌ موثَّق يُشير إلى سطر العجز نفسه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            // week | half | month | custom — ما اختاره من أنشأها، والمدّة نفسها في from/to
            $table->string('period', 10)->default('custom');
            $table->date('from_date');
            $table->date('to_date');
            $table->string('status', 12)->default('draft'); // draft | approved

            $table->unsignedInteger('couriers_count')->default(0);
            $table->unsignedInteger('settlements_count')->default(0);
            $table->unsignedInteger('shipments_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);

            // ما استلمه المندوبون من الزبائن، ومنه للتجّار، والأجور (إيراد الشركة)، والأجور التي جاءت نقداً معه
            $table->bigInteger('cod_collected')->default(0);
            $table->bigInteger('merchant_money')->default(0);
            $table->bigInteger('delivery_fees')->default(0);
            $table->bigInteger('fees_in_cash')->default(0);
            $table->bigInteger('courier_commissions')->default(0);
            $table->bigInteger('deductions')->default(0);
            $table->bigInteger('costs')->default(0);

            // المتوقَّع تسليمه بالكشوف، وما عُدَّ فعلاً، والفرق (سالبه عجز)، وما سُدِّد من عجزٍ سابق
            $table->bigInteger('expected_cash')->default(0);
            $table->bigInteger('received_cash')->default(0);
            $table->bigInteger('discrepancy')->default(0);
            $table->bigInteger('remainders')->default(0);

            // الربح المحاسبيّ (الأجور − العمولات − التكاليف)، والمتحقّق نقداً (ما بقي في اليد للشركة)
            $table->bigInteger('net_profit')->default(0);
            $table->bigInteger('realized_profit')->default(0);

            $table->string('notes', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'to_date'], 'colb_status_idx');
            $table->index(['company_id', 'branch_id', 'to_date'], 'colb_branch_idx');
        });

        Schema::create('collection_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_batch_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12)->default('settlement'); // settlement | remainder
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            // الكشف في وجبةٍ واحدة لا غير
            $table->foreignId('courier_settlement_id')->nullable()->unique()->constrained()->restrictOnDelete();
            // تحصيلٌ لعجزٍ سابق: سطر الكشف الذي نقص
            $table->foreignId('remainder_of_item_id')->nullable()->constrained('collection_batch_items')->restrictOnDelete();

            $table->unsignedInteger('shipments_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->bigInteger('cod_collected')->default(0);
            $table->bigInteger('merchant_money')->default(0);
            $table->bigInteger('delivery_fees')->default(0);
            $table->bigInteger('courier_commissions')->default(0);
            $table->bigInteger('deductions')->default(0);
            $table->bigInteger('expected_cash')->default(0);
            $table->bigInteger('received_cash')->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'courier_id'], 'colb_item_courier_idx');
        });

        Schema::create('collection_batch_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_batch_id')->constrained()->cascadeOnDelete();
            // المصروف في وجبةٍ واحدة لا غير
            $table->foreignId('expense_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->timestamps();
        });

        // سجلّ الوجبة: كل إضافةٍ وتعديلٍ واعتمادٍ وتصحيحٍ بعده، بقيمته قبل وبعد وسببه
        Schema::create('collection_batch_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 120)->nullable();
            $table->string('action', 30);
            $table->string('subject', 160)->nullable();
            $table->string('before', 120)->nullable();
            $table->string('after', 120)->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'collection_batch_id'], 'colb_audit_batch_idx');
        });

        // سحب الأرباح: عمليةٌ منفصلة عن تحصيل المناديب، تخرج من صندوقٍ إن اختير
        Schema::create('profit_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->bigInteger('amount');
            $table->date('withdrawn_on');
            $table->string('withdrawn_by', 120)->nullable(); // من استلم المبلغ
            $table->foreignId('cash_box_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->string('status', 12)->default('done'); // done | cancelled
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'withdrawn_on'], 'profit_wd_status_idx');
        });

        /*
        | «العوائد» صلاحيةٌ جديدة (money.profits)، والمرتبة تحلّ محلّ افتراضي الدور: تُعطى لكل مرتبةٍ
        | ترى أرباح الشركة (reports.financial مع money.view) — كالمحاسب. وسحب الأرباح لصاحب الشركة.
        */
        foreach (DB::table('ranks')->get(['id', 'abilities']) as $rank) {
            $abilities = json_decode((string) $rank->abilities, true);

            if (is_array($abilities) && in_array('money.view', $abilities, true)
                && in_array('reports.financial', $abilities, true) && ! in_array('money.profits', $abilities, true)) {
                DB::table('ranks')->where('id', $rank->id)
                    ->update(['abilities' => json_encode([...$abilities, 'money.profits'])]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('ranks')->get(['id', 'abilities']) as $rank) {
            $abilities = json_decode((string) $rank->abilities, true);

            if (is_array($abilities) && in_array('money.profits', $abilities, true)) {
                DB::table('ranks')->where('id', $rank->id)
                    ->update(['abilities' => json_encode(array_values(array_diff($abilities, ['money.profits'])))]);
            }
        }

        Schema::dropIfExists('profit_withdrawals');
        Schema::dropIfExists('collection_batch_audits');
        Schema::dropIfExists('collection_batch_costs');
        Schema::dropIfExists('collection_batch_items');
        Schema::dropIfExists('collection_batches');
    }
};
