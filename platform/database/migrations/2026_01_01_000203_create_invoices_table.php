<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فواتير النواة على الشركات: اشتراك شهري + عمولة الشحنات المسلّمة في الفترة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number', 30)->unique();
            $table->date('period_start');
            $table->date('period_end');

            $table->bigInteger('subscription_amount')->default(0);
            $table->bigInteger('commission_amount')->default(0);
            $table->unsignedInteger('billable_shipments')->default(0);
            $table->bigInteger('adjustments')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('amount_paid')->default(0);

            $table->enum('status', ['draft', 'issued', 'paid', 'overdue', 'void'])->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
