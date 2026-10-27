<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * موظّفو التاجر (docs/plan/87): حساباتٌ يضيفها صاحب المتجر لمتجره، ولكلٍّ ما يفتحه له — الإدخال، والطابعة،
 * والمالية… فارغٌ = صاحب المتجر، يفتح كلّ شيء كما كان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('merchant_permissions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('merchant_permissions');
        });
    }
};
