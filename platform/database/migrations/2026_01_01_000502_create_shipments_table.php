<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الشحنة — قلب النظام.
 *
 * قرارات مثبّتة هنا ولا تُراجَع لاحقاً:
 *  1) company_id في الجدول من أول migration، لا يُضاف لاحقاً.
 *  2) كل مبلغ BIGINT بالدينار العراقي الصحيح — لا float ولا decimal.
 *  3) landmark إلزامي: العراق بلا رموز بريدية عاملة، وأقرب نقطة دالّة
 *     هي ما يوصل المندوب فعلاً.
 *  4) status نصّ لا ENUM — إضافة حالة جديدة بعد مليون صف يجب ألّا
 *     تحتاج ALTER TABLE. المفردات محكومة بـ App\Enums\ShipmentStatus.
 *  5) مندوب الاستلام ومندوب التوصيل حقلان منفصلان.
 *
 * معادلة المال:
 *   total_fees   = delivery_fee + extra_fee + cod_fee - discount
 *   merchant_due = collected_amount - total_fees        (حين يدفع التاجر الأجور)
 *   merchant_due = collected_amount - cod_fee + discount (حين يدفعها الزبون)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();

            // ---------- الهوية ----------
            $table->string('number', 30);                       // رقم الوصل الظاهر للزبون
            $table->string('barcode', 40)->nullable();
            $table->string('merchant_reference', 60)->nullable(); // رقم الطلب عند التاجر
            $table->string('type', 20)->default('delivery');      // delivery, return, exchange
            $table->foreignId('replacement_for_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->foreignId('pickup_request_id')->nullable()->constrained()->nullOnDelete();

            // ---------- المستلم ----------
            $table->string('recipient_name', 160);
            $table->string('recipient_phone', 20);
            $table->string('recipient_phone_alt', 20)->nullable();
            $table->foreignId('governorate_id')->constrained()->restrictOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address', 500);
            $table->string('landmark', 255);                      // إلزامي
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            // ---------- المحتوى ----------
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('pieces_count')->default(1);
            $table->unsignedInteger('weight_grams')->default(0);
            $table->boolean('is_fragile')->default(false);
            $table->boolean('allow_open')->default(false);        // يُسمح للزبون بفتح الطرد
            $table->text('notes')->nullable();

            // ---------- المال (د.ع) ----------
            $table->bigInteger('cod_amount')->default(0);         // المطلوب تحصيله
            $table->bigInteger('collected_amount')->default(0);   // المحصَّل فعلاً
            $table->bigInteger('delivery_fee')->default(0);
            $table->bigInteger('extra_fee')->default(0);
            $table->bigInteger('cod_fee')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('total_fees')->default(0);
            $table->bigInteger('merchant_due')->default(0);
            $table->bigInteger('courier_commission')->default(0);
            $table->bigInteger('platform_commission')->default(0); // حصّة النواة
            $table->string('fees_paid_by', 10)->default('merchant'); // merchant | customer
            $table->string('payment_type', 12)->default('cod');      // cod | prepaid | free

            // ---------- الحالة ----------
            $table->string('status', 30)->default('created');
            $table->timestamp('status_changed_at')->nullable();
            $table->unsignedTinyInteger('attempts_count')->default(0);
            $table->foreignId('last_failure_reason_id')->nullable()->constrained('failure_reasons')->nullOnDelete();

            // ---------- الموقع والمسؤولية ----------
            $table->foreignId('hub_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pickup_courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->foreignId('delivery_courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->foreignId('current_bag_id')->nullable();

            // ---------- الزمن ----------
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // ---------- التسوية ----------
            $table->foreignId('courier_settlement_id')->nullable();
            $table->foreignId('merchant_settlement_id')->nullable();
            $table->timestamp('courier_settled_at')->nullable();
            $table->timestamp('merchant_settled_at')->nullable();
            $table->boolean('is_invoiced')->default(false);       // دخلت فاتورة النواة

            $table->foreignId('created_by_user_id')->nullable();
            $table->string('source', 20)->default('web');          // web | app | api | import
            $table->timestamps();
            $table->softDeletes();

            // ---------- الفهارس ----------
            // عند 500 طلب/يوم لكل شركة (~15 ألف شهرياً) هذه الفهارس هي الفرق
            // بين شاشة تفتح في 80ms وأخرى تفتح في 8 ثوانٍ بعد سنة.
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'created_at'], 'sh_status_idx');
            $table->index(['company_id', 'merchant_id', 'status'], 'sh_merchant_idx');
            $table->index(['company_id', 'delivery_courier_id', 'status'], 'sh_courier_idx');
            $table->index(['company_id', 'governorate_id', 'status'], 'sh_gov_idx');
            $table->index(['company_id', 'hub_id', 'status'], 'sh_hub_idx');
            $table->index(['company_id', 'created_at'], 'sh_created_idx');
            $table->index(['company_id', 'recipient_phone'], 'sh_phone_idx');   // بحث خدمة العملاء
            $table->index(['company_id', 'merchant_settled_at'], 'sh_msettle_idx');
            $table->index(['company_id', 'courier_settled_at'], 'sh_csettle_idx');
            $table->index('barcode');
            $table->index(['company_id', 'branch_id', 'status'], 'sh_branch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
