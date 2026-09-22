<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_settlement_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();

            $table->string('shipment_status', 30);      // لقطة الحالة وقت التسوية
            $table->bigInteger('collected_amount')->default(0);
            $table->bigInteger('delivery_fee')->default(0);
            $table->bigInteger('return_fee')->default(0);
            $table->bigInteger('cod_fee')->default(0);
            $table->bigInteger('net_amount')->default(0);
            $table->string('note', 255)->nullable();

            $table->unique(['merchant_settlement_id', 'shipment_id'], 'mss_unique');
            $table->index(['company_id', 'shipment_id'], 'mss_shipment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_settlement_shipments');
    }
};
