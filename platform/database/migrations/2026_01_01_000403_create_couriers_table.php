<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المندوبون. النوع يفصل مندوب الاستلام عن مندوب التوصيل —
 * وظيفتان مختلفتان بحسابَين وأرباح وتقارير منفصلة، وهذا قرار غير قابل للتأجيل.
 *
 * رقمان منفصلان لا رقم واحد مختلط:
 *   cash_in_hand       = نقد الشركة الموجود فعلاً بجيب المندوب
 *   commission_balance = ما تدين به الشركة له من عمولات لم تُدفَع
 * خلطهما يعطي رقماً لا يُجيب أي سؤال: لا "كم نقد بيده؟" ولا "كم له؟".
 * عند التسوية: الواجب تسليمه = cash_in_hand − commission_balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 20);

            $table->string('name', 160);
            $table->string('phone', 20);
            $table->string('national_id', 40)->nullable();

            $table->enum('type', ['delivery', 'pickup', 'both'])->default('delivery');
            $table->enum('vehicle_type', ['motorcycle', 'car', 'van', 'truck', 'on_foot'])->default('motorcycle');
            $table->string('vehicle_number', 40)->nullable();

            // عمولة المندوب — تُغلَب على قيمة الشركة الافتراضية إن وُجدت
            $table->bigInteger('commission_per_delivery')->nullable();
            $table->bigInteger('commission_per_pickup')->nullable();
            $table->bigInteger('commission_per_return')->nullable();

            $table->bigInteger('cash_in_hand')->default(0);
            $table->bigInteger('commission_balance')->default(0);
            $table->bigInteger('cash_limit')->default(0);    // 0 = بلا سقف

            $table->enum('status', ['active', 'suspended', 'inactive'])->default('active');
            $table->boolean('is_available')->default(true);
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'phone']);
            $table->index(['company_id', 'status', 'type']);
            $table->index(['company_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couriers');
    }
};
