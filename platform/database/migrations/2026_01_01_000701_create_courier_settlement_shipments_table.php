<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سطور التسوية — لقطة مُجمَّدة للمبالغ وقت التسوية.
 * لو تغيّر سعر الشحنة لاحقاً لا يتغيّر الكشف المُقفَل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_settlement_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();

            $table->bigInteger('collected_amount')->default(0);
            $table->bigInteger('commission')->default(0);
            $table->bigInteger('deduction')->default(0);
            $table->string('note', 255)->nullable();

            $table->unique(['courier_settlement_id', 'shipment_id'], 'css_unique');
            $table->index(['company_id', 'shipment_id'], 'css_shipment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_settlement_shipments');
    }
};
