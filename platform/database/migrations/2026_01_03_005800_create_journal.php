<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفتر القيود المزدوج (docs/plan/68، المرحلة الأولى): كل حركةٍ ماليةٍ قائمة تكتب قيداً
 * متوازناً بمفتاحٍ فريد من مصدرها، فلا يُكتب مرّتين.
 *
 * الشركات التي لها حركاتٌ قبل الدفتر تبدأ «غير مهيّأة»: لا تُكتب قيودها الحيّة حتى
 * يعيد finance:journal-backfill بناء تاريخها (يجري مجدولاً). والجديدة تبدأ مهيّأة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_heads', function (Blueprint $table) {
            $table->foreignId('company_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->char('last_hash', 64)->nullable();
            $table->timestamp('initialized_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('number');
            $table->date('entry_date');
            $table->string('event', 40);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key', 100);
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('amount')->default(0); // مجموع المدين = مجموع الدائن
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'idempotency_key'], 'je_key_unique');
            $table->unique(['company_id', 'number'], 'je_number_unique');
            $table->index(['company_id', 'entry_date'], 'je_date_idx');
            $table->index(['company_id', 'source_type', 'source_id'], 'je_source_idx');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->string('account', 8);
            $table->unsignedBigInteger('debit')->default(0);
            $table->unsignedBigInteger('credit')->default(0);
            $table->date('entry_date');
            // الأبعاد: بها تُصفّى اللوحة ويُتتبَّع كل رقم
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('courier_id')->nullable();
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->unsignedBigInteger('shipment_id')->nullable();
            $table->unsignedBigInteger('cash_box_id')->nullable();
            $table->unsignedBigInteger('expense_category_id')->nullable();

            $table->index(['company_id', 'account', 'entry_date'], 'jl_account_idx');
            $table->index(['company_id', 'shipment_id', 'account'], 'jl_shipment_idx');
            $table->index(['company_id', 'courier_id', 'account'], 'jl_courier_idx');
            $table->index(['company_id', 'merchant_id', 'account'], 'jl_merchant_idx');
            $table->index(['company_id', 'cash_box_id', 'account'], 'jl_box_idx');
            $table->index(['company_id', 'branch_id', 'account', 'entry_date'], 'jl_branch_idx');
        });

        // شركاتٌ لها مالٌ قبل الدفتر: تنتظر إعادة بناء تاريخها؛ والباقي يبدأ الآن
        $withHistory = DB::table('transactions')->select('company_id')
            ->union(DB::table('cash_movements')->select('company_id'))
            ->union(DB::table('expenses')->select('company_id'))
            ->pluck('company_id')->unique()->all();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            DB::table('journal_heads')->insert([
                'company_id'     => $companyId,
                'initialized_at' => in_array($companyId, $withHistory) ? null : now(),
                'updated_at'     => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('journal_heads');
    }
};
