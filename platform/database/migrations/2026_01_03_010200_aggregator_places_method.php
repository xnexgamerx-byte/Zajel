<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كيف رُبطت المنطقة (docs/plan/104): name بالاسم الكامل، prefix بأول الاسم (تُراجَع لأنّها تخمينٌ مقيَّد)،
 * وmanual بيد الموظّف. ما رُبط قبل هذا يبقى manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aggregator_places', function (Blueprint $table) {
            $table->string('method', 10)->default('manual')->after('remote_name');
        });
    }

    public function down(): void
    {
        Schema::table('aggregator_places', function (Blueprint $table) {
            $table->dropColumn('method');
        });
    }
};
