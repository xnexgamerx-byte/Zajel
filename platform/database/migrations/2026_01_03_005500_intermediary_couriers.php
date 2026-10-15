<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الشركة الوسيطة مندوبٌ عندنا (docs/plan/64): تُسلَّم لها الشحنات كما تُسلَّم لأيّ مندوب، وتُحاسَب
 * بكشف المندوب نفسه، ويحدّث نظامها حالاتها بمفتاح ربطٍ مربوطٍ بها.
 *
 * - couriers.is_intermediary: المندوب شركةٌ وسيطة يُفتح لها الربط.
 * - courier_api_keys: مفتاح ربطٍ للمندوب الوسيط، يُخزَّن مُجزّأً فلا يُقرأ من قاعدة البيانات، وله
 *   صلاحيات محدّدة وانتهاء وقائمة عناوين اختيارية.
 *
 * وتُرفع جداول «الشركاء» التي سبقت هذا التصميم إن بقيت فارغة — ولا يُمسّ ما فيه بيانات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->boolean('is_intermediary')->default(false)->after('type');
        });

        Schema::create('courier_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->char('token_hash', 64)->unique();
            $table->string('token_hint', 12);          // آخر أحرف المفتاح للتعرّف عليه في القائمة
            $table->json('abilities');
            $table->json('allowed_ips')->nullable();   // null = من أيّ عنوان
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'courier_id']);
        });

        $this->dropEmptyPartnerTables();
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_api_keys');
        Schema::table('couriers', function (Blueprint $table) {
            $table->dropColumn('is_intermediary');
        });
    }

    private function dropEmptyPartnerTables(): void
    {
        if (! Schema::hasTable('partners')) {
            return;
        }

        $empty = DB::table('partners')->doesntExist()
            && (! Schema::hasTable('partner_keys') || DB::table('partner_keys')->doesntExist())
            && (! Schema::hasTable('partner_reports') || DB::table('partner_reports')->doesntExist());

        if (! $empty) {
            return;
        }

        if (Schema::hasColumn('shipments', 'partner_id')) {
            // المفتاح الأجنبي، ثم الفهرس الذي أُضيف معه صراحةً، ثم العمود
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropForeign(['partner_id']);
            });
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropIndex(['partner_id']);
            });
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropColumn('partner_id');
            });
        }
        Schema::dropIfExists('partner_reports');
        Schema::dropIfExists('partner_keys');
        Schema::dropIfExists('partners');
    }
};
