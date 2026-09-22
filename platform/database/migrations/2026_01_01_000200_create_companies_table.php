<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المستأجرون: كل شركة توصيل مشتركة في زاجل (الزاجل، البرق، الزعيم، برايم...).
 * هذا هو الجذر الذي تتفرّع منه كل بيانات النظام — كل جدول تشغيلي يحمل company_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 40)->unique();          // النطاق الفرعي: zajel.zajel.iq
            $table->string('name', 160);                   // الاسم التجاري بالعربي
            $table->string('name_en', 160)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 9)->default('#1F6FEB');
            $table->string('phone', 20)->nullable();
            $table->string('email', 160)->nullable();
            $table->foreignId('governorate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address', 255)->nullable();

            // دورة حياة الاشتراك من منظور النواة
            $table->enum('status', ['trial', 'active', 'suspended', 'cancelled'])->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason', 255)->nullable();

            // إعدادات العرض والتشغيل الخاصة بالشركة
            $table->json('settings')->nullable();
            $table->char('currency', 3)->default('IQD');
            $table->string('timezone', 40)->default('Asia/Baghdad');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
