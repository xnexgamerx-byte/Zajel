<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * محادثة الكول سنتر مع المندوب (docs/plan/38).
 *
 * محادثةٌ واحدة لكل مندوب، كمحادثة الهاتف: المكتب يكتب «اتّصل بالزبون، يقول إنه في
 * البيت»، والمندوب يكتب «الزبون لا يردّ» — وكل سطرٍ يحمل رقم شحنته إن كان عنها.
 * والرسائل إضافةٌ فقط: سجلُّ ما قيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id');
            $table->string('last_author', 10)->default('staff');   // staff | courier
            $table->timestamp('last_message_at')->nullable();
            $table->boolean('staff_unread')->default(false);
            $table->boolean('courier_unread')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'courier_id'], 'ct_courier_unique');
            $table->index(['company_id', 'last_message_at'], 'ct_inbox_idx');
        });

        Schema::create('courier_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_thread_id')->constrained()->cascadeOnDelete();
            $table->string('author', 10);                         // staff | courier
            $table->foreignId('user_id')->nullable();
            $table->string('author_name', 160)->nullable();       // لقطة: يبقى لو حُذف الحساب
            $table->foreignId('shipment_id')->nullable();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['courier_thread_id', 'id'], 'cmsg_thread_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_messages');
        Schema::dropIfExists('courier_threads');
    }
};
