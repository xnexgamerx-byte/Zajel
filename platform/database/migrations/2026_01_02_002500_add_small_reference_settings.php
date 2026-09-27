<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إعدادات المعتاد الصغيرة:
 *
 * «واتساب الدعم» لكل محافظة — زبون البصرة يكلّم فرع البصرة لا بغداد.
 * «إعلانات الصفحة الرئيسية بالتطبيق» — صورٌ بترتيبها أعلى بوابة التاجر وتطبيق المندوب.
 * و«محادثة الشحنة» بملفّاتها — صورة تلفٍ أو وصلٍ تُرفق بالرسالة نفسها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governorate_settings', function (Blueprint $table) {
            $table->string('whatsapp', 20)->nullable()->after('courier_fee_peripheral');
        });

        Schema::create('app_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('image_path', 255);
            $table->string('link_url', 255)->nullable();
            $table->string('audience', 12)->default('merchants'); // merchants | couriers | all
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active', 'sort_order']);
        });

        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->string('attachment_path', 255)->nullable()->after('body');
            $table->string('attachment_name', 160)->nullable()->after('attachment_path');
            $table->string('attachment_mime', 80)->nullable()->after('attachment_name');
            $table->unsignedInteger('attachment_size')->nullable()->after('attachment_mime');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size']);
        });

        Schema::dropIfExists('app_ads');

        Schema::table('governorate_settings', fn (Blueprint $table) => $table->dropColumn('whatsapp'));
    }
};
