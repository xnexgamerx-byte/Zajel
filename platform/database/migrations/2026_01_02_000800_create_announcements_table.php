<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الإشعارات الجماعية — إعلانٌ واحد لجمهور، لا صفٌّ لكل مستلم.
 *
 * إشعارٌ لخمسمئة تاجر في جدول notifications المعتاد خمسمئة صفّ، ومندوبٌ
 * يُعيَّن غداً لا يرى ما أُعلن قبله ولو كان يخصّه («العمولة تتغيّر من
 * الشهر القادم»). فالجمهور يُقرأ عند العرض لا عند الإرسال، والقراءة
 * تُسجَّل لمن قرأ وحده — والمرسِل يرى «قرأه ٣٤ من ٥٢».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('audience', 30);   // delivery_couriers, pickup_couriers, merchants
            $table->string('title', 160);
            $table->text('body');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'audience', 'created_at'], 'ann_audience_idx');
        });

        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();

            // قراءةٌ واحدة لكل مستخدم مهما فتح
            $table->unique(['announcement_id', 'user_id']);
            $table->index(['company_id', 'user_id'], 'ann_read_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcements');
    }
};
