<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اشتراك شركة في باقة. الأسعار مُجمّدة وقت الاشتراك (snapshot)
 * حتى لا يتغيّر عقد شركة قائمة عند تعديل الباقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            $table->enum('status', ['trialing', 'active', 'past_due', 'cancelled', 'expired'])->default('active');
            $table->enum('billing_cycle', ['monthly', 'yearly'])->default('monthly');

            $table->bigInteger('price')->default(0);
            $table->bigInteger('commission_per_shipment')->default(0);
            $table->decimal('commission_percent', 5, 2)->default(0);

            $table->date('starts_at');
            $table->date('ends_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['status', 'ends_at']);   // لتنبيهات قرب الانتهاء
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
