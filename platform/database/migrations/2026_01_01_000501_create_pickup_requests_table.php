<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلب استلام من التاجر: "عندي 30 طرد، دزّ مندوب".
 * يُسنَد إلى مندوب استلام (type = pickup) لا إلى مندوب توصيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number', 30);
            $table->string('status', 30)->default('pending');   // pending, assigned, in_progress, completed, cancelled

            $table->unsignedInteger('expected_count')->default(0);
            $table->unsignedInteger('actual_count')->default(0);

            $table->string('address', 255)->nullable();
            $table->string('landmark', 255)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('contact_phone', 20)->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->string('notes', 500)->nullable();

            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'scheduled_at'], 'pr_status_idx');
            $table->index(['company_id', 'merchant_id', 'status'], 'pr_merchant_idx');
            $table->index(['company_id', 'courier_id', 'status'], 'pr_courier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_requests');
    }
};
