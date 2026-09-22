<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ التدقيق. كل انتحال شخصية (impersonation) من النواة يُسجَّل هنا —
 * هذا شرط ثقة الشركات المستأجرة: يحق لها أن ترى متى دخلتَ إلى بياناتها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->string('user_name', 160)->nullable();
            $table->foreignId('impersonator_user_id')->nullable();

            $table->string('action', 60);                 // created, updated, deleted, impersonated, exported
            $table->string('auditable_type', 80)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at'], 'al_company_idx');
            $table->index(['auditable_type', 'auditable_id'], 'al_subject_idx');
            $table->index(['user_id', 'created_at'], 'al_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
