<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التجّار — زبائن شركة التوصيل.
 *
 * balance = ما تدين به الشركة للتاجر بالدينار.
 *   موجب = للتاجر عند الشركة مال لم يُدفَع بعد
 *   سالب = التاجر مدين للشركة (أجور رواجع أكثر من محصَّلاته)
 * يُشتقّ من جدول transactions ولا يُكتب إلا معه في المعاملة نفسها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 20);

            $table->string('business_name', 200);      // اسم المتجر
            $table->string('owner_name', 160)->nullable();
            $table->string('phone', 20);
            $table->string('phone_alt', 20)->nullable();
            $table->string('email', 160)->nullable();

            $table->foreignId('governorate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address', 255)->nullable();
            $table->string('landmark', 255)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('settlement_cycle', ['daily', 'weekly', 'biweekly', 'monthly', 'on_demand'])->default('weekly');

            // بيانات الدفع للتاجر
            $table->enum('payout_method', ['cash', 'zaincash', 'asiahawala', 'fastpay', 'qi', 'fib', 'bank_transfer'])
                ->default('cash');
            $table->string('payout_account', 120)->nullable();

            $table->bigInteger('balance')->default(0);   // د.ع — انظر شرح الدلالة أعلاه
            $table->enum('status', ['active', 'suspended', 'pending'])->default('active');
            $table->string('notes', 500)->nullable();

            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'phone']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'business_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
