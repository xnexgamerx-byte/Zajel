<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المناطق/الأقضية داخل المحافظة. العراق بلا رموز بريدية عاملة،
 * فالمنطقة + أقرب نقطة دالّة (landmark) هما العنوان الحقيقي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governorate_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar', 120);
            $table->string('name_en', 120)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['governorate_id', 'is_active']);
            $table->unique(['governorate_id', 'name_ar']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
