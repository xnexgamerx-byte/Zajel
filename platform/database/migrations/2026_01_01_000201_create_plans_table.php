<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * باقات الاشتراك التي تبيعها النواة للشركات.
 * كل المبالغ BIGINT بالدينار العراقي الصحيح (لا كسور — أصغر ورقة 250 د.ع).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('name_en', 120)->nullable();
            $table->string('description', 500)->nullable();

            $table->bigInteger('price_monthly')->default(0);   // د.ع
            $table->bigInteger('price_yearly')->default(0);    // د.ع

            // نموذج العمولة على الشحنة (يُجمَع مع الاشتراك أو يحلّ محله)
            $table->bigInteger('commission_per_shipment')->default(0);
            $table->decimal('commission_percent', 5, 2)->default(0);

            // حدود الباقة — null = بلا حد
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_couriers')->nullable();
            $table->unsignedInteger('max_merchants')->nullable();
            $table->unsignedInteger('max_shipments_per_month')->nullable();

            // مفاتيح تفعيل الوحدات: pickup_agents, api_access, webhooks, hubs, branded_apps ...
            $table->json('features')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
