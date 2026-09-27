<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «شحنات ممسوحة» كما في المعتاد: من مسح الشحنة ومتى ولماذا — فتُسترجَع
 * بعلمٍ، ويُرى من يمسح كثيراً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('deleted_by_user_id')->nullable()->after('deleted_at');
            $table->string('deleted_by_name', 160)->nullable()->after('deleted_by_user_id');
            $table->string('delete_reason', 255)->nullable()->after('deleted_by_name');

            $table->index(['company_id', 'deleted_at'], 'sh_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_deleted_idx');
            $table->dropColumn(['deleted_by_user_id', 'deleted_by_name', 'delete_reason']);
        });
    }
};
