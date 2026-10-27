<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اختيار التاجر لإشعارات هاتفه (docs/plan/86): أيّها يصل هاتفه — الواصل، وللمعالجة، وقرار المتابعة، وتغيّر
 * المبلغ — وبصوتٍ أم بلا صوت. فارغٌ = كلّها بصوت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notify_prefs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_prefs');
        });
    }
};
