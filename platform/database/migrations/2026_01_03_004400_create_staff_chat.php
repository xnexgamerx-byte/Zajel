<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مراسلة الموظّفين داخل النظام (docs/plan/41): موظّفٌ يكتب لموظّف، أو لقسمٍ كلّه —
 * «موظّف الراجع»، «الحسابات» — بلا واتساب ولا وسيلةٍ خارجية.
 *
 * محادثة القسم واحدة: كل من في القسم يراها ويردّ فيها، ومن كتب إليها من خارجه
 * يبقى فيها. والقراءة لكل موظّفٍ وحده: آخر رسالةٍ قرأها برقمها (last_read_id) لا بوقتها —
 * رسالتان في الثانية نفسها لا تختلطان. والرسائل إضافةٌ فقط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // القسم: «rank:5» مرتبة الشركة، أو «role:accountant» دورٌ بلا مرتبة. فارغٌ = بين موظّفَين
            $table->string('team', 40)->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'team'], 'st_team_unique');
            $table->index(['company_id', 'last_message_at'], 'st_inbox_idx');
        });

        Schema::create('staff_thread_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('last_read_id')->nullable();

            $table->unique(['staff_thread_id', 'user_id'], 'stm_unique');
            $table->index('user_id', 'stm_user_idx');
        });

        Schema::create('staff_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->string('author_name', 160)->nullable();       // لقطة: يبقى لو حُذف الحساب
            $table->foreignId('shipment_id')->nullable();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['staff_thread_id', 'id'], 'smsg_thread_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_messages');
        Schema::dropIfExists('staff_thread_members');
        Schema::dropIfExists('staff_threads');
    }
};
