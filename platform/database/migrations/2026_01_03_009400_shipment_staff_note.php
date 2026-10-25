<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «ملاحظة للموظّفين» على الشحنة (docs/plan/83): سطرٌ تكتبه موظّفة الكول سنتر — «اتّصلتُ ولم يردّ» —
 * فتعرف زميلتها التي تفتح المؤجّل بعدها أنّه اتُّصل به. باسم من كتبها ووقتها، ولا يراها التاجر ولا المندوب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('staff_note', 255)->nullable();
            $table->string('staff_note_by', 120)->nullable();
            $table->timestamp('staff_note_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['staff_note', 'staff_note_by', 'staff_note_at']);
        });
    }
};
