<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| إشعارٌ لتاجرٍ بعينه (docs/plan/61): «تغيّر مبلغ وصلك» يصل جرسه هو، لا كل التجّار. الإعلانات
| العامّة تبقى بلا تاجر.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->foreignId('merchant_id')->nullable()->after('audience')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merchant_id');
        });
    }
};
