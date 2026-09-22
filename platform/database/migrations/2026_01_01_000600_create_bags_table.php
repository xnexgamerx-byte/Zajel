<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأكياس/الطرود المجمّعة. نقل 300 شحنة من بغداد إلى البصرة يعني
 * مسح 300 باركود مرتين — أو مسح كيس واحد مرتين. الكيس هو الفرق
 * بين عملية تستغرق ساعة وعملية تستغرق دقيقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->foreignId('from_hub_id')->constrained('hubs')->restrictOnDelete();
            $table->foreignId('to_hub_id')->constrained('hubs')->restrictOnDelete();

            $table->string('status', 20)->default('open'); // open, sealed, in_transit, received, opened
            $table->unsignedInteger('shipments_count')->default(0);

            $table->timestamp('sealed_at')->nullable();
            $table->foreignId('sealed_by_user_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by_user_id')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status'], 'bag_status_idx');
            $table->index(['company_id', 'to_hub_id', 'status'], 'bag_dest_idx');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreign('current_bag_id')->references('id')->on('bags')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['current_bag_id']);
        });
        Schema::dropIfExists('bags');
    }
};
