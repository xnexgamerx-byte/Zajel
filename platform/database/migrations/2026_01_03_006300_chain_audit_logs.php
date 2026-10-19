<?php

use App\Support\Audit\AuditChain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سلسلة بصمات سجلّ التدقيق (docs/plan/68 §١٣): كل سطرٍ يحمل بصمة سابقه في سلسلة شركته
 * (وللنواة سلسلتها)، فأيّ حذفٍ أو تعديلٍ مباشرٍ في القاعدة يكسرها ويظهر في الفحص اليومي.
 * والسبب عمودٌ بذاته. والسطور القائمة تُربط بالسلسلة بترتيبها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('reason', 255)->nullable()->after('new_values');
            $table->char('prev_hash', 64)->nullable()->after('user_agent');
            $table->char('hash', 64)->nullable()->after('prev_hash');
        });

        // رأس كل سلسلة: آخر بصمة. الشركة برقمها، والنواة صفر
        Schema::create('audit_heads', function (Blueprint $table) {
            $table->unsignedBigInteger('chain')->primary();
            $table->unsignedBigInteger('last_id')->nullable();
            $table->char('last_hash', 64)->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        $heads = [];
        DB::table('audit_logs')->orderBy('id')->chunk(1000, function ($rows) use (&$heads) {
            foreach ($rows as $row) {
                $chain = (int) ($row->company_id ?? 0);
                $prev = $heads[$chain]['hash'] ?? null;
                $hash = AuditChain::hash((array) $row, $prev);

                DB::table('audit_logs')->where('id', $row->id)->update(['prev_hash' => $prev, 'hash' => $hash]);
                $heads[$chain] = ['id' => $row->id, 'hash' => $hash];
            }
        });

        foreach ($heads as $chain => $head) {
            DB::table('audit_heads')->insert(['chain' => $chain, 'last_id' => $head['id'], 'last_hash' => $head['hash'], 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_heads');
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['reason', 'prev_hash', 'hash']);
        });
    }
};
