<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نقطة تحقّقٍ لسلسلة سجلّ التدقيق كالتي في رأس الدفتر (2026_01_03_006600): الشاشات تفحص ما
 * جاء بعد آخر سطرٍ سلم، والفحص الكامل كل ليلة (AuditChain::brokenAt). السجلّ يكبر بكل عملية،
 * وفحصه من أوّله في كل فتحةٍ لميزان المراجعة يكبر معه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_heads', function (Blueprint $table) {
            $table->unsignedBigInteger('verified_id')->nullable()->after('last_hash');
            $table->char('verified_hash', 64)->nullable()->after('verified_id');
            $table->timestamp('verified_at')->nullable()->after('verified_hash');
            // انكسرت عند هذا السطر في آخر فحص: تبقى الشاشات تقوله حتى يسلم فحصٌ كامل
            $table->unsignedBigInteger('broken_id')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('audit_heads', function (Blueprint $table) {
            $table->dropColumn(['verified_id', 'verified_hash', 'verified_at', 'broken_id']);
        });
    }
};
