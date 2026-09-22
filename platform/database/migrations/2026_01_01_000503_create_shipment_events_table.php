<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ أحداث الشحنة — جدول إضافة فقط (append-only)، لا تعديل ولا حذف.
 * هو مصدر الحقيقة: حقل status على الشحنة مجرّد اختصار لآخر حدث هنا.
 *
 * حجم متوقَّع: 500 شحنة/يوم × ~10 أحداث = 5000 صف/يوم لكل شركة،
 * أي ~1.8 مليون صف سنوياً لكل شركة. لذلك:
 *   - لا updated_at (نصف الكتابات تختفي)
 *   - الفهرس الأساسي للاستعلام هو (shipment_id, id) وليس created_at
 *   - عند تجاوز ~50 مليون صف تُقسَّم بالتاريخ (PARTITION BY RANGE)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('event_type', 30)->default('status_change'); // status_change, scan, note, money, assign

            // من فعلها
            $table->string('actor_type', 20)->default('user');  // user, courier, merchant, system, api
            $table->foreignId('actor_id')->nullable();
            $table->string('actor_name', 160)->nullable();       // لقطة اسم — يبقى صحيحاً لو حُذف الحساب

            $table->foreignId('courier_id')->nullable();
            $table->foreignId('hub_id')->nullable();
            $table->foreignId('failure_reason_id')->nullable();

            $table->bigInteger('amount')->nullable();            // للأحداث المالية
            $table->string('note', 500)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['shipment_id', 'id'], 'se_shipment_idx');
            $table->index(['company_id', 'created_at'], 'se_company_time_idx');
            $table->index(['company_id', 'to_status', 'created_at'], 'se_status_idx');
            $table->index(['company_id', 'courier_id', 'created_at'], 'se_courier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
    }
};
