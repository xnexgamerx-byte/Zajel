<?php

namespace App\Actions\Billing;

use App\Models\AuditLog;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * تجديد الاشتراكات التي مضى أجلها وهي تتجدّد تلقائياً.
 *
 * كان auto_renew علَماً لا يقرؤه أحد: الاشتراك الشهري يُنشأ بأجل شهرٍ
 * ثم لا يتحرّك أجله أبداً، فيبدو بعد شهره الأوّل «منتهياً» إلى الأبد —
 * وكانت لوحة المنصّة تُخفي ذلك بعرضه «ينتهي اليوم». الفوترة لا تقرأ
 * الأجل فلم يضع مال، لكن الأجل صار بلا معنى.
 *
 * يتقدّم الأجل دورةً دورة حتى يعود مستقبلاً — اشتراكٌ فاتته ثلاثة أشهر
 * يتقدّم ثلاث دورات لا واحدة. والتجريبيّ لا يتجدّد من تلقاء نفسه:
 * تحويل التجربة إلى اشتراكٍ مدفوع قرارٌ لا ساعة.
 */
class RenewSubscriptions
{
    /** @return Collection<int, Subscription> المُجدَّدة */
    public function handle(?Carbon $today = null): Collection
    {
        $today = ($today ?? now())->copy()->startOfDay();

        return Tenancy::runAsPlatform(function () use ($today) {
            $due = Subscription::acrossCompanies()
                ->where('status', 'active')
                ->where('auto_renew', true)
                ->whereNull('cancelled_at')
                ->where('ends_at', '<', $today->toDateString())
                ->get();

            foreach ($due as $subscription) {
                $from = $subscription->ends_at->copy();
                $ends = $from->copy();
                $cycles = 0;

                /*
                | الأجل يُحسب من يوم البدء في كل دورة لا من الأجل السابق:
                | بلا فيضان (٣١ كانون الثاني + شهر = آخر شباط لا ٣ آذار)،
                | وبلا انجراف (آذار يعود إلى ٣١ لا يبقى على ٢٨ إلى الأبد).
                */
                $step = 0;

                while ($ends->lte($from) || $ends->lt($today)) {
                    $step++;
                    $ends = $subscription->billing_cycle === 'yearly'
                        ? $subscription->starts_at->copy()->addYearsNoOverflow($step)
                        : $subscription->starts_at->copy()->addMonthsNoOverflow($step);

                    if ($ends->gt($from)) {
                        $cycles++;
                    }
                }

                $subscription->forceFill(['ends_at' => $ends->toDateString()])->save();

                AuditLog::create([
                    'company_id'     => $subscription->company_id,
                    'action'         => 'subscription_renewed',
                    'auditable_type' => 'subscription',
                    'auditable_id'   => $subscription->id,
                    'old_values'     => ['ends_at' => $from->toDateString()],
                    'new_values'     => ['ends_at' => $ends->toDateString(), 'cycles' => $cycles],
                ]);
            }

            return $due;
        });
    }
}
