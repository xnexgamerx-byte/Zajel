<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كل المستخدمين في جدول واحد.
 *   company_id = null  ->  مستخدم النواة (أنت وفريق الدعم)
 *   company_id = X     ->  مستخدم داخل شركة X ولا يرى غيرها أبداً
 *
 * تسجيل الدخول بالهاتف لا بالبريد — هذا هو العُرف في العراق.
 * ملاحظة: الفهرس الفريد (company_id, phone) لا يمنع تكرار الهاتف
 * بين مستخدمَي نواة (MySQL يعتبر NULL مميّزاً)، لذا يُفرض ذلك في التحقّق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable();

            $table->string('name', 160);
            $table->string('phone', 20);
            $table->string('email', 160)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // platform_admin, platform_support | company_owner, company_admin,
            // branch_manager, operations, customer_service, accountant, courier, merchant
            $table->string('role', 40)->index();

            // ربط الحساب بسجلّه التشغيلي عند الحاجة
            $table->foreignId('courier_id')->nullable();
            $table->foreignId('merchant_id')->nullable();

            $table->string('avatar_path')->nullable();
            $table->string('locale', 5)->default('ar');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'phone']);
            $table->index(['company_id', 'role', 'is_active']);
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
