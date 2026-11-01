<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * منطقةٌ عندنا في مدينةٍ أخرى عندهم (docs/plan/104): «طوزخورماتو» في صلاح الدين عندنا وفي كركوك عندهم.
 * فارغٌ: مدينتها مدينة محافظتها. يُرسل الطلب بمدينتها هذه لا بمدينة المحافظة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aggregator_places', function (Blueprint $table) {
            $table->string('remote_city_id', 20)->nullable()->after('remote_id');
        });
    }

    public function down(): void
    {
        Schema::table('aggregator_places', function (Blueprint $table) {
            $table->dropColumn('remote_city_id');
        });
    }
};
