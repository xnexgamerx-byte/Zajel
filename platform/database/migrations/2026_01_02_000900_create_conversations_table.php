<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المحادثات بين الشركة وتجّارها.
 *
 * «أين شحنتي؟» يُسأل اليوم على واتساب موظّفٍ بعينه: يضيع إن غاب، ولا
 * يراه زميله، ولا يُعرف كم سؤالاً بلا جواب. المحادثة هنا للشركة لا
 * للموظّف: يراها كل مَن يردّ، وتُعلَّق بالشحنة إن كانت عنها، وتُعَدّ
 * غير المُجابة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 160);
            $table->string('status', 10)->default('open');      // open, closed

            // مَن كتب آخر سطر: «التاجر» تعني أن الكرة عندنا
            $table->string('last_author', 10)->default('merchant');
            $table->timestamp('last_message_at')->nullable();
            $table->boolean('staff_unread')->default(true);
            $table->boolean('merchant_unread')->default(false);

            $table->timestamps();

            $table->index(['company_id', 'status', 'last_message_at'], 'conv_inbox_idx');
            $table->index(['company_id', 'merchant_id', 'last_message_at'], 'conv_merchant_idx');
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('author', 10);                        // merchant, staff
            $table->foreignId('user_id')->nullable();
            $table->string('author_name', 160)->nullable();      // لقطة: يبقى لو حُذف الحساب
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'id'], 'cm_thread_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }
};
