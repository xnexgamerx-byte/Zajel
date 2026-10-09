<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الرسالة الثابتة للتاجر (docs/plan/41): نصٌّ جاهز يُنسخ أو يُرسَل بواتساب من شاشة المعالجة،
 * ولكل موظّفٍ نصّه. فارغٌ = القالب الذي يأتي مع النظام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('merchant_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('merchant_message'));
    }
};
