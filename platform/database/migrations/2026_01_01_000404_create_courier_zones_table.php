<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مناطق تغطية المندوب — تُستخدم في التوزيع التلقائي واقتراح المندوب الأقرب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('governorate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['courier_id', 'governorate_id', 'city_id'], 'courier_zone_unique');
            $table->index(['company_id', 'governorate_id', 'city_id'], 'cz_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_zones');
    }
};
