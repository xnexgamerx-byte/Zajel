<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الإعلان لفرعه: ما يرسله فرعٌ غير الرئيسي يبلغ تجّاره ومناديبه وحدهم، وما
 * يرسله الفرع الرئيسي يبلغ الشركة كلّها (branch_id فارغ) — كما كان كل إعلانٍ قبله.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('audience')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
