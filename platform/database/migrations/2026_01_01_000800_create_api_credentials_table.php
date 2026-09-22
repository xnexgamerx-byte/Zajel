<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مفاتيح API لكل شركة — بها يربط التاجر متجره (سلة، ووكومرس، شوبيفاي) مباشرة.
 * السرّ يُخزَّن مُجزّأً (hash) ولا يُعرَض إلا مرّة واحدة عند الإنشاء.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('key', 64)->unique();
            $table->string('secret_hash');
            $table->json('scopes')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->default(120);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_credentials');
    }
};
