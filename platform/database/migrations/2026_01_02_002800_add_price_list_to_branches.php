<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تسعيرة الفرع: قائمة أسعارٍ يختارها الفرع الرئيسي لكل فرع، تسري على تجّاره
 * ما لم يكن للتاجر تسعيرته الخاصّة. فارغاً: افتراضية الشركة، كما كان كل فرعٍ قبله.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('price_list_id')->nullable()->after('is_main')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_list_id');
        });
    }
};
