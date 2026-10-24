<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «الطابعة» (docs/plan/79): متى طُبع وصل الشحنة أوّل مرّة — فالجديدة التي لم يستلمها المندوب تُفرز
 * «للطباعة» و«مطبوعة»، ولا تختلط بما هو قيد التوصيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('printed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('printed_at');
        });
    }
};
