<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حجم الشحنة بتسعيرته (docs/plan/38): عاديٌّ افتراضاً، ومتوسّطٌ وكبيرٌ وخاصّ بزيادةٍ على
 * أجرة التوصيل تكتبها الشركة في كل تسعيرة — فتاجرٌ بتسعيرةٍ خاصّة يُعطى زيادته هو.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->json('size_fees')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', fn (Blueprint $table) => $table->dropColumn('size_fees'));
    }
};
