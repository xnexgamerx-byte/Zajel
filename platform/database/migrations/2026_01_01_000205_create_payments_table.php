<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعات الشركات للنواة. طرق الدفع عراقية: نقد، زين كاش، آسيا حوالة،
 * فاست باي، Qi كارد، FIB، حوالة مصرفية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->bigInteger('amount');
            $table->enum('method', ['cash', 'zaincash', 'asiahawala', 'fastpay', 'qi', 'fib', 'bank_transfer', 'other'])
                ->default('cash');
            $table->string('reference', 120)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('recorded_by_user_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
