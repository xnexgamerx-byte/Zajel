<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المناورة بين الفروع: كشف النقل يحمله مندوب نقلٍ من مندوبي الشركة (نوعه «نقل بين الفروع»)،
 * فيُعرف مع مَن الشحنة وهي في الطريق بين محافظتين. والسائق من خارج الشركة يبقى نصّاً كما كان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            $table->foreignId('courier_id')->nullable()->after('to_hub_id')
                ->constrained('couriers')->nullOnDelete();
            $table->index(['company_id', 'courier_id', 'status'], 'mf_courier_idx');
        });
    }

    public function down(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            $table->dropIndex('mf_courier_idx');
            $table->dropConstrainedForeignId('courier_id');
        });
    }
};
