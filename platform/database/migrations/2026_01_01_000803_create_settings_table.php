<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إعدادات مفتاح/قيمة. company_id = null -> إعداد عام للنواة يرثه الجميع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string, int, bool, json
            $table->string('group', 40)->default('general');
            $table->timestamps();

            $table->unique(['company_id', 'key']);
            $table->index(['company_id', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
