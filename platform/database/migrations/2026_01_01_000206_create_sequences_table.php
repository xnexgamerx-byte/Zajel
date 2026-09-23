<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عدّادات الترقيم لكل شركة: رقم الوصل، رقم الكيس، رقم الكشف، رقم التسوية.
 *
 * لماذا جدول مستقل بدل MAX(number)+1؟
 * لأن مندوبَين يُنشئان شحنة في الثانية نفسها يحصلان على الرقم نفسه،
 * فتفشل إحداهما. القفل على صفّ العدّاد يجعل الترقيم متسلسلاً ومضموناً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);              // shipment, bag, manifest, ...
            $table->string('prefix', 10)->default('');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->unsignedTinyInteger('pad')->default(6);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['company_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
