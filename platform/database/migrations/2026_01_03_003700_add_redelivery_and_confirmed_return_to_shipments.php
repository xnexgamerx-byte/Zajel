<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مرحلتان يعرفهما الكول سنتر ولم تكن لهما خانة (docs/plan/38):
 *
 * redelivery_at: «إعادة توصيل» — محاولةٌ فشلت فعالجها الكول سنتر وأعادها للتوصيل. تبقى
 * «قيد التوصيل» في حالتها، وتُعرض في خانتها لا مع ما خرج أوّل مرّة، فيُعرف ما عالجه.
 * تُمحى حين تفشل ثانيةً أو تؤجَّل: صار لها قرارٌ جديد.
 *
 * return_confirmed_at: «راجع مؤكد» — لم تُعالَج فتأكّد رجوعها بقرارٍ باسم صاحبه. تسير بعدها
 * راجعاً إلى مخزن المندوب ثم إلى فرع تاجرها، ولا تعود شحنةً جديدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('redelivery_at')->nullable()->after('assigned_at');
            $table->timestamp('return_confirmed_at')->nullable()->after('returned_at');
            $table->unsignedBigInteger('return_confirmed_by_user_id')->nullable()->after('return_confirmed_at');

            $table->index(['company_id', 'redelivery_at', 'status'], 'sh_redelivery_idx');
        });

        /*
        | ما قُرّر قبل هذه الخانات يُعلَّم من سجلّ الشحنة نفسه، فلا يبدأ النظام بلا «راجع
        | مؤكد» ولا «إعادة توصيل»:
        |  - راجعٌ ما زال بيد المندوب، رجع بقرارٍ بعد محاولةٍ فاشلة أو تأجيل أو من المخزن.
        |  - مع المندوب أو في المخزن، وآخر قرارٍ عليها «إعادة توصيل» لم تفشل بعده ولم تؤجَّل.
        */
        DB::table('shipments')
            ->where('status', 'returning')
            ->whereNull('return_received_at')
            ->whereNull('return_confirmed_at')
            ->update(['return_confirmed_at' => DB::raw("(select max(e.created_at) from shipment_events e
                where e.shipment_id = shipments.id and e.to_status = 'returning'
                and e.from_status in ('failed_attempt', 'postponed', 'at_hub'))")]);

        DB::table('shipments')
            ->whereIn('status', ['out_for_delivery', 'at_hub'])
            ->whereNull('redelivery_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('shipment_events as p')
                ->whereColumn('p.shipment_id', 'shipments.id')
                ->where('p.event_type', 'processed')
                ->where('p.meta', 'like', '%"action":"redeliver"%')
                ->whereNotExists(fn ($later) => $later->selectRaw('1')->from('shipment_events as f')
                    ->whereColumn('f.shipment_id', 'shipments.id')
                    ->whereIn('f.to_status', ['failed_attempt', 'postponed'])
                    ->whereColumn('f.id', '>', 'p.id')))
            ->update(['redelivery_at' => DB::raw("(select max(p2.created_at) from shipment_events p2
                where p2.shipment_id = shipments.id and p2.event_type = 'processed')")]);
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_redelivery_idx');
            $table->dropColumn(['redelivery_at', 'return_confirmed_at', 'return_confirmed_by_user_id']);
        });
    }
};
