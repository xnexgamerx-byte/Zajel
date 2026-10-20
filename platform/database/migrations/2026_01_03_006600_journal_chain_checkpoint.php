<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * آخر ما سلم من سلسلة البصمات في رأس الدفتر: الشاشات تفحص ما جاء بعده وحده، والفحص الكامل
 * كل ليلة (Journal::brokenAt). على مليون سطرٍ كان الفحص الكامل في كل فتحةٍ لميزان المراجعة دقائق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_heads', function (Blueprint $table) {
            $table->unsignedBigInteger('verified_number')->nullable()->after('last_hash');
            $table->char('verified_hash', 64)->nullable()->after('verified_number');
            $table->timestamp('verified_at')->nullable()->after('verified_hash');
            // انكسرت عند هذا القيد في آخر فحص: تبقى الشاشات تقوله حتى يسلم فحصٌ كامل
            $table->unsignedBigInteger('broken_number')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('journal_heads', function (Blueprint $table) {
            $table->dropColumn(['verified_number', 'verified_hash', 'verified_at', 'broken_number']);
        });
    }
};
