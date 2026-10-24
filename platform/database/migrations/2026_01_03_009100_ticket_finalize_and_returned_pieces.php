<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المندوب يسجّل عند الباب «سُلّمت مع تغيير السعر» أو «واصل جزئي» بعدد القطع الراجعة، وينتظر الموظّف
 * (docs/plan/80): الاعتماد يسجّلها بنفسه (finalize)، والقطع الراجعة تبقى على الشحنة فتظهر في الراجع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_tickets', function (Blueprint $table) {
            $table->boolean('finalize')->default(false);
            $table->unsignedSmallInteger('returned_pieces')->nullable();
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedSmallInteger('returned_pieces')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shipment_tickets', function (Blueprint $table) {
            $table->dropColumn(['finalize', 'returned_pieces']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('returned_pieces');
        });
    }
};
