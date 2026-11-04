<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تفرّد الرقم في الشركة ينتقل من القاعدة إلى النظام (docs/plan/116): ما زال لا يتكرّر — النظام يرفضه ويقول لمن هو —
 * إلّا لشركةٍ فتحت لها المنصّة «رقمٌ واحد لأكثر من تاجر» (Feature::SharedPhones)، فيُسجَّل تاجرها برقمٍ يحمله غيره.
 * واسم المستخدم يبقى فريداً في القاعدة: به يُعرف الحساب عند الدخول.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // الفهرس الجديد قبل حذف القديم: MySQL لا يحذف فهرساً يستند إليه مفتاحٌ أجنبيّ على company_id
            $table->index(['company_id', 'phone']);
            $table->dropUnique(['company_id', 'phone']);
        });

        Schema::table('merchants', function (Blueprint $table) {
            // الفهرس الجديد قبل حذف القديم: MySQL لا يحذف فهرساً يستند إليه مفتاحٌ أجنبيّ على company_id
            $table->index(['company_id', 'phone']);
            $table->dropUnique(['company_id', 'phone']);
        });
    }

    public function down(): void
    {
        // يفشل إن تكرّر رقمٌ بعد فتح الميزة: يُصحَّح الرقم أوّلاً
        Schema::table('merchants', function (Blueprint $table) {
            $table->unique(['company_id', 'phone']);
            $table->dropIndex(['company_id', 'phone']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique(['company_id', 'phone']);
            $table->dropIndex(['company_id', 'phone']);
        });
    }
};
