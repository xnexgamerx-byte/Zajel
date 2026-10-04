<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تخصيص «لوحة اليوم»: اختصاراتٌ إلى الشاشات، والأقسام، وقوائم التنبيهات — لكل موظّفٍ
 * تخصيصه، ولكل مرتبةٍ ما يراه أصحابها ما لم يخصّصوا (App\Support\HomeLayout).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('home_layout')->nullable();
        });

        Schema::table('ranks', function (Blueprint $table) {
            $table->json('home_layout')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('home_layout'));
        Schema::table('ranks', fn (Blueprint $table) => $table->dropColumn('home_layout'));
    }
};
