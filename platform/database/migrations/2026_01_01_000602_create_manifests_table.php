<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كشف النقل بين المراكز: سيارة واحدة، سائق واحد، عدّة أكياس.
 * لو اختفى كيس في الطريق، الكشف هو الذي يثبت من استلمه ومن سلّمه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manifests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->foreignId('from_hub_id')->constrained('hubs')->restrictOnDelete();
            $table->foreignId('to_hub_id')->constrained('hubs')->restrictOnDelete();

            $table->string('status', 20)->default('draft'); // draft, dispatched, in_transit, arrived, closed
            $table->string('driver_name', 160)->nullable();
            $table->string('driver_phone', 20)->nullable();
            $table->string('vehicle_number', 40)->nullable();

            $table->unsignedInteger('bags_count')->default(0);
            $table->unsignedInteger('shipments_count')->default(0);

            $table->timestamp('departed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->foreignId('dispatched_by_user_id')->nullable();
            $table->foreignId('received_by_user_id')->nullable();
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status'], 'mf_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifests');
    }
};
