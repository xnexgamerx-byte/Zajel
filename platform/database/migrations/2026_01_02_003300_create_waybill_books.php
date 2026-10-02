<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الوصولات المطبوعة مسبقاً (docs/plan/23): دفاتر أرقامٍ يطبعها التاجر من بوابته —
 * أو الشركة له — ويكتب عليها بيده، ثم تُدخَل الشحنة بمسح الوصل.
 *
 * الدفتر مدىً من تسلسل الشركة (from_serial..to_serial)، ورقم الوصل المطبوع
 * ٩٠٬٠٠٠٬٠٠٠ + التسلسل (WaybillBook::codeFor). والشحنة المُدخلة منه تحمل الرقم
 * في barcode — حيث يبحث عنه كل مسحٍ في النظام — ودفترها في waybill_book_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waybill_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('from_serial');
            $table->unsignedInteger('to_serial');
            $table->unsignedSmallInteger('size');
            $table->string('source', 10)->default('office'); // office | portal
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'from_serial'], 'wb_serial_idx');
            $table->index(['company_id', 'merchant_id', 'id'], 'wb_merchant_idx');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('waybill_book_id')->nullable()->after('barcode')
                ->constrained('waybill_books')->nullOnDelete();
            $table->index(['company_id', 'waybill_book_id'], 'sh_waybill_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_waybill_idx');
            $table->dropConstrainedForeignId('waybill_book_id');
        });

        Schema::dropIfExists('waybill_books');
    }
};
