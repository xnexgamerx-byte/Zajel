<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دورة المال بين المنصّة والشركات (docs/plan/36).
 *
 * platform_settings: إعدادات المنصّة نفسها — طرق الدفع التي تراها الشركات، ومهلة الإيقاف
 * التلقائي بعد موعد الفاتورة، وأيام التنبيه قبل انتهاء الاشتراك.
 *
 * payment_notices: دفعةٌ تُبلغ عنها الشركة من «اشتراك الشركة وفواتيرها» (بإيصالها إن شاءت)،
 * تؤكّدها المنصّة فتصير دفعةً مسجّلة، أو ترفضها بسبب تقرؤه الشركة.
 *
 * companies.billing_exempt: لا يوقفها التأخّر (شركة صاحب المنصّة مثلاً).
 * companies.suspension_source: platform بيد المنصّة، وbilling بالتأخّر — وهذه وحدها تعود
 * وحدها حين يُسدَّد ما فات المهلة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('method', 20);
            $table->string('reference', 120)->nullable();
            $table->date('paid_on');
            $table->string('note', 500)->nullable();

            $table->string('proof_path')->nullable();
            $table->string('proof_name')->nullable();
            $table->string('proof_mime', 80)->nullable();
            $table->unsignedInteger('proof_size')->nullable();

            $table->enum('status', ['pending', 'confirmed', 'rejected'])->default('pending');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 160)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['company_id', 'status']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('billing_exempt')->default(false)->after('suspended_reason');
            $table->string('suspension_source', 20)->nullable()->after('suspended_reason');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['billing_exempt', 'suspension_source']));
        Schema::dropIfExists('payment_notices');
        Schema::dropIfExists('platform_settings');
    }
};
