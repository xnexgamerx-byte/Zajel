<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * الواصل الجزئي (الوثيقة ٢٤): أجرة التوصيل كاملةً، وباقيه يرجع لتاجره بلا أجرة راجعٍ ولا
 * عمولة إرجاع. والنظام يعرفه بساعة تسليمه (delivered_at) — وكانت لا تُكتب إلّا للواصل كلّه.
 *
 * فتُملأ لما سُلِّم بعضه من قبل، من أوّل حدث «واصل جزئي» في سجلّه؛ وتُصفَّر أجرة رجوع
 * باقيه ما لم يرجع بعد. وما رجع قبل القرار يبقى كما قُيِّد: لا يُمحى من الدفتر شيء.
 * يُعاد بلا ضرر: لا يمسّ إلّا ما ساعته فارغة، وما لم يرجع.
 */
return new class extends Migration
{
    public function up(): void
    {
        // شركةً شركة: فهرس (الشركة، الحالة) يجد أحداث «واصل جزئي» بلا مسحٍ لسجلّ الأحداث كلّه
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $partial = DB::table('shipment_events')
                ->where('company_id', $companyId)
                ->where('to_status', 'partially_delivered')
                ->groupBy('shipment_id')
                ->selectRaw('shipment_id, min(created_at) as at');

            DB::table('shipments')
                ->where('shipments.company_id', $companyId)
                ->whereNull('shipments.delivered_at')
                ->joinSub($partial, 'partial', 'partial.shipment_id', '=', 'shipments.id')
                ->select('shipments.id', 'partial.at')
                // بالمعرّف لا بالصفحات: ما يُملأ يخرج من الشرط، فالصفحات تقفز فوق صفوف
                ->chunkById(500, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('shipments')->where('id', $row->id)->update(['delivered_at' => $row->at]);
                    }
                }, 'shipments.id', 'id');
        }

        DB::table('shipments')
            ->whereNotNull('delivered_at')
            ->whereNotIn('status', ['delivered', 'returned'])
            ->where('return_fee', '>', 0)
            ->update(['return_fee' => 0]);
    }

    public function down(): void
    {
        // ساعة التسليم صحيحةٌ للواصل الجزئي، وأجرة رجوع باقيه صفرٌ بالقرار: لا رجوع عنهما
    }
};
