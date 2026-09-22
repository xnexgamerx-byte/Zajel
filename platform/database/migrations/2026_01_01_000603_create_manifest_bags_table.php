<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manifest_bags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manifest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bag_id')->constrained()->cascadeOnDelete();
            $table->timestamp('loaded_at')->nullable();
            $table->timestamp('unloaded_at')->nullable();
            $table->boolean('is_missing')->default(false);

            $table->unique(['manifest_id', 'bag_id']);
            $table->index(['company_id', 'bag_id'], 'mb_bag_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifest_bags');
    }
};
