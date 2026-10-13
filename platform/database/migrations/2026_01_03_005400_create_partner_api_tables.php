<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الشركاء الذين تُسلَّم لهم الشحنات (docs/plan/64): وسيطٌ يوزّع في المحافظات، بفروعه ومفاتيح ربطه.
 *
 * - partners: الشريك نفسه، تابعٌ لشركتنا (الشركة المالكة للشحنة).
 * - partner_keys: مفتاح ربطٍ لكل فرعٍ أو تكامل، يُخزَّن مُجزّأً فلا يُقرأ من قاعدة البيانات، وله
 *   صلاحيات محدّدة وانتهاء وقائمة عناوين اختيارية.
 * - partner_reports: طلبات «سُلِّم» من الشريك، بانتظار مراجعتنا قبل أن تنحسب مالياً.
 * - shipments.partner_id: الشريك الذي معه الطرد الآن. يُصفَّر حين يعود إلينا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('status', 20)->default('active'); // active | suspended
            $table->timestamps();
        });

        Schema::create('partner_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
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

            $table->index(['company_id', 'partner_id']);
        });

        Schema::create('partner_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_key_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                // delivered
            $table->string('note', 500)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('reported_at');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision', 20)->nullable(); // approved | rejected
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'decided_at']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('hub_id')->constrained('partners')->nullOnDelete();
            $table->index('partner_id');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
        });
        Schema::dropIfExists('partner_reports');
        Schema::dropIfExists('partner_keys');
        Schema::dropIfExists('partners');
    }
};
