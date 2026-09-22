<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['subscription', 'commission', 'addon', 'discount', 'adjustment']);
            $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('unit_price')->default(0);
            $table->bigInteger('amount')->default(0);
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
