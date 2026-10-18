<?php

use App\Support\Backfill\CourierReturnReceipts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «إيصالات راجع المندوبين»: كلّ استلامٍ لراجع مندوبٍ بالمخزن إيصالٌ برقم (RC…)، كإيصال
 * الراجع للتاجر من الجهة الأخرى. وما استُلم قبلها يُجمع في إيصالاته (CourierReturnReceipts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_return_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('shipments_count')->default(0);
            $table->unsignedBigInteger('cod_total')->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamp('received_at');
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'courier_id', 'received_at'], 'crr_courier_idx');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('courier_return_receipt_id')->nullable()->after('return_batch_id');
            $table->index(['company_id', 'courier_return_receipt_id'], 'sh_courier_return_receipt_idx');
        });

        CourierReturnReceipts::run();
    }

    public function down(): void
    {
        // الفهرس ثم العمود في خطوتين: SQLite لا يُسقط عموداً مفهرساً في الخطوة نفسها
        Schema::table('shipments', fn (Blueprint $table) => $table->dropIndex('sh_courier_return_receipt_idx'));
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn('courier_return_receipt_id'));

        Schema::dropIfExists('courier_return_receipts');
    }
};
