<?php

namespace App\Actions\Billing;

use App\Enums\Feature;
use App\Models\Company;
use App\Models\CompanyFeature;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shipment;
use App\Models\Subscription;
use App\Support\Arabic;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * فاتورة شهر واحد على شركة واحدة: اشتراك + عمولة الشحنات المسلَّمة + رسوم ميزاتها
 * المفتوحة برسمٍ شهريّ (docs/plan/35).
 *
 * ثلاثة قرارات تحكم هذا الإجراء:
 *  1) رقم الفاتورة مشتقّ من الشركة والفترة، فمحاولة فوترة الشهر نفسه
 *     مرتين تصطدم بقيد فريد بدل أن تُصدر فاتورتين لنفس العمل.
 *  2) العمولة تُحسب من الاشتراك لا من الباقة: أسعار الاشتراك مجمَّدة،
 *     فتعديل الباقة اليوم لا يرفع فاتورة شهر مضى.
 *  3) كل شحنة تُفوتَر تُوسَم is_invoiced وتُجمَّد عمولتها عليها،
 *     فلا تدخل فاتورة ثانية ولو أُعيد التوليد.
 */
class GenerateInvoice
{
    public function handle(Company $company, CarbonImmutable $periodStart, bool $issue = true): Invoice
    {
        $start = $periodStart->startOfMonth();
        $end = $start->endOfMonth();
        $number = $this->numberFor($company, $start);

        $existing = Invoice::acrossCompanies()->where('number', $number)->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'period' => "فاتورة {$start->format('Y-m')} لهذه الشركة صادرة بالفعل برقم {$number}.",
            ]);
        }

        return DB::transaction(function () use ($company, $start, $end, $number, $issue) {
            return Tenancy::runFor($company, function () use ($company, $start, $end, $number, $issue) {
                $subscription = $this->subscriptionFor($start, $end);

                $invoice = Invoice::create([
                    'subscription_id' => $subscription?->id,
                    'number'          => $number,
                    'period_start'    => $start->toDateString(),
                    'period_end'      => $end->toDateString(),
                    'status'          => $issue ? 'issued' : 'draft',
                    'issued_at'       => $issue ? now() : null,
                    'due_at'          => $end->addDays(7)->toDateString(),
                ]);

                $subscriptionAmount = $this->addSubscriptionLine($invoice, $subscription, $start);
                [$commission, $count] = $this->addCommissionLines($invoice, $subscription, $start, $end);
                $features = $this->addFeatureLines($invoice, $start, $end);

                // فاتورة بصفر ليست فاتورة: شركة اشتركت بعد الفترة أو لم
                // تسلّم شيئاً لا تُرسَل لها ورقة فارغة. المعاملة تُلغى كاملة
                // فلا يبقى رقم فاتورة محجوزاً بلا مقابل.
                if ($subscriptionAmount === 0 && $commission === 0 && $features === 0) {
                    throw ValidationException::withMessages([
                        'period' => 'لا اشتراك ولا شحنات مفوترة ولا ميزات برسمٍ في هذه الفترة.',
                    ]);
                }

                $invoice->forceFill([
                    'subscription_amount' => $subscriptionAmount,
                    'commission_amount'   => $commission,
                    'features_amount'     => $features,
                    'billable_shipments'  => $count,
                    'total'               => $subscriptionAmount + $commission + $features,
                ])->save();

                return $invoice->refresh();
            });
        });
    }

    /** رقم مشتقّ لا متسلسل: يمنع ازدواج الفوترة بقيد قاعدة البيانات نفسه. */
    public function numberFor(Company $company, CarbonImmutable $periodStart): string
    {
        return 'INV-'.$periodStart->format('Ym').'-'.str_pad((string) $company->id, 4, '0', STR_PAD_LEFT);
    }

    /** الاشتراك الذي كان سارياً في تلك الفترة، لا الاشتراك الحالي. */
    protected function subscriptionFor(CarbonImmutable $start, CarbonImmutable $end): ?Subscription
    {
        return Subscription::query()
            ->where('starts_at', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>=', $start))
            ->orderByDesc('id')
            ->first();
    }

    protected function addSubscriptionLine(Invoice $invoice, ?Subscription $subscription, CarbonImmutable $start): int
    {
        if (! $subscription || $subscription->price === 0) {
            return 0;
        }

        // اشتراك سنوي يُفوتَر في شهر بدايته فقط، لا كل شهر
        if ($subscription->billing_cycle === 'yearly'
            && $subscription->starts_at->format('m') !== $start->format('m')) {
            return 0;
        }

        $amount = (int) $subscription->price;
        $label = $subscription->billing_cycle === 'yearly' ? 'اشتراك سنوي' : 'اشتراك شهري';

        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'type'        => 'subscription',
            'description' => "{$label} — {$start->format('Y-m')}",
            'quantity'    => 1,
            'unit_price'  => $amount,
            'amount'      => $amount,
        ]);

        return $amount;
    }

    /**
     * رسوم الميزات المفتوحة برسمٍ شهريّ، يوماً بيوم: ميزةٌ فُتحت في منتصف الشهر أو أُغلقت
     * فيه تُحسب بأيّامها — واليوم الذي استُعملت فيه ولو ساعةً يومٌ — ويومٌ تغيّر فيه رسمها
     * بآخر رسمٍ فيه. ولكل ميزةٍ سطرٌ مقرَّبٌ إلى ٢٥٠ دينار، أصغر ورقةٍ تُدفع.
     */
    protected function addFeatureLines(Invoice $invoice, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $decisions = CompanyFeature::query()
            ->where('enabled', true)
            ->where('monthly_price', '>', 0)
            ->where('starts_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $start))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->groupBy('feature');

        $month = $start->daysInMonth;
        $total = 0;

        foreach ($decisions as $key => $periods) {
            // يومٌ من الشهر => الرسم الشهريّ الساري فيه
            $days = [];
            foreach ($periods as $period) {
                $from = $start->max(CarbonImmutable::instance($period->starts_at)->startOfDay());
                $until = $period->ends_at ? $end->min(CarbonImmutable::instance($period->ends_at)) : $end;

                for ($day = $from; $day <= $until; $day = $day->addDay()) {
                    $days[$day->day] = (int) $period->monthly_price;
                }
            }

            $amount = (int) (round(array_sum($days) / $month / 250) * 250);

            if ($amount === 0) {
                continue;
            }

            $label = Feature::tryFrom($key)?->label() ?? $key;
            $whole = count($days) === $month && count(array_unique($days)) === 1;

            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'type'        => 'addon',
                'description' => "ميزة «{$label}» — ".($whole
                    ? $start->format('Y-m')
                    : Arabic::days(count($days))." من {$month} في ".$start->format('Y-m')),
                'quantity'    => 1,
                'unit_price'  => $amount,
                'amount'      => $amount,
            ]);

            $total += $amount;
        }

        return $total;
    }

    /** @return array{0:int,1:int} [مبلغ العمولة, عدد الشحنات] */
    protected function addCommissionLines(
        Invoice $invoice,
        ?Subscription $subscription,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $perShipment = (int) ($subscription?->commission_per_shipment ?? 0);
        $percent = (float) ($subscription?->commission_percent ?? 0);

        if ($perShipment === 0 && $percent === 0.0) {
            return [0, 0];
        }

        $billable = Shipment::query()
            ->where('is_invoiced', false)
            // سُلِّمت كلّها أو بعضها في الشهر — ولو رجع باقي الواصل الجزئي بعدها
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$start, $end]);

        $count = (clone $billable)->count();

        if ($count === 0) {
            return [0, 0];
        }

        // تُجمَّد العمولة على كل شحنة، فتبقى الفاتورة قابلة للتدقيق سطراً سطراً
        $ids = (clone $billable)->pluck('id');

        Shipment::whereIn('id', $ids)->update([
            'platform_commission' => DB::raw(
                'ROUND(collected_amount * '.$percent.' / 100) + '.$perShipment
            ),
            'is_invoiced' => true,
        ]);

        $amount = (int) Shipment::whereIn('id', $ids)->sum('platform_commission');

        if ($perShipment > 0) {
            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'type'        => 'commission',
                'description' => 'عمولة الشحنات المسلَّمة — '.\App\Support\Arabic::shipments($count),
                'quantity'    => $count,
                'unit_price'  => $perShipment,
                'amount'      => $count * $perShipment,
            ]);
        }

        if ($percent > 0.0) {
            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'type'        => 'commission',
                'description' => "نسبة {$percent}% من قيمة ".\App\Support\Arabic::shipments($count),
                'quantity'    => 1,
                'unit_price'  => $amount - ($count * $perShipment),
                'amount'      => $amount - ($count * $perShipment),
            ]);
        }

        return [$amount, $count];
    }
}
