<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تأمينات التجّار.
 *
 * التاجر يترك مبلغاً ضماناً — عن راجعٍ لا يستلمه، أو بضاعةٍ تتلف، أو
 * رصيدٍ يسلب. وهو **ليس رصيده**: خلطه بحسابه يجعل كشفه يُظهر مالاً
 * يملكه وهو محجوز، ويجعل ردّه عند إنهاء التعامل بلا أثر يُراجَع.
 *
 * فله دفتره: إيداعٌ وردٌّ وخصمٌ، ولكل حركة سببها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable();
            $table->foreignId('merchant_id');

            $table->string('direction', 8);        // in | out
            $table->string('kind', 20);            // deposit | refund | forfeit
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');

            $table->foreignId('cash_box_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'merchant_id', 'id'], 'md_merchant_idx');
        });

        Schema::table('merchants', function (Blueprint $table) {
            // تسريع قراءة — الحقيقة في merchant_deposits
            $table->bigInteger('deposit_balance')->default(0)->after('balance');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn('deposit_balance'));
        Schema::dropIfExists('merchant_deposits');
    }
};
