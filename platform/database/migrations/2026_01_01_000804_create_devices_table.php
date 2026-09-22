<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أجهزة الإشعارات. app يفصل تطبيق المندوب عن تطبيق التاجر
 * لأن لكل شركة تطبيقَين بعلامتها التجارية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('app', 20);          // courier | merchant
            $table->string('platform', 10);     // android | ios | web
            $table->string('token', 255);
            $table->string('app_version', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique('token');
            $table->index(['company_id', 'user_id', 'app'], 'dev_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
