<?php

namespace App\Support\Billing;

use App\Actions\Billing\ChangeSubscription;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * ما على الشركة للمنصّة الآن (docs/plan/36): فواتيرها التي لم تُسدَّد، وأقدم ما تأخّر منها،
 * ومتى يتوقّف نظامها إن بقيت، ومتى ينتهي اشتراكها أو تجربتها إن لم يتجدّدا.
 *
 * تقرؤه صفحة «اشتراك الشركة وفواتيرها» والتنبيه أعلى نظامها، وEnforceDues ليلاً — فما يُقال
 * للشركة هو ما يُطبَّق عليها.
 */
final class Dues
{
    /** صدرت ولم تُسدَّد كاملةً */
    public const OPEN = ['issued', 'overdue'];

    /**
     * @param Collection<int, Invoice> $open بموعدها، الأقدم أوّلاً
     */
    private function __construct(
        public readonly Company $company,
        public readonly Collection $open,
        public readonly ?Subscription $subscription,
        private readonly CarbonImmutable $today,
    ) {}

    public static function for(Company $company, ?CarbonImmutable $today = null): self
    {
        return new self(
            $company,
            Invoice::acrossCompanies()
                ->where('company_id', $company->id)
                ->whereIn('status', self::OPEN)
                ->whereColumn('amount_paid', '<', 'total')
                ->orderBy('due_at')
                ->orderBy('id')
                ->get(),
            Subscription::acrossCompanies()
                ->where('company_id', $company->id)
                ->whereIn('status', ChangeSubscription::LIVE)
                ->with('plan:id,name')
                ->latest('id')
                ->first(),
            $today ?? CarbonImmutable::today(),
        );
    }

    /** المتبقّي على الشركة في كل فواتيرها */
    public function balance(): int
    {
        return (int) $this->open->sum(fn (Invoice $invoice) => $invoice->balanceDue());
    }

    /** @return Collection<int, Invoice> فات موعدها */
    public function overdue(): Collection
    {
        return $this->open->filter(fn (Invoice $invoice) => $invoice->due_at && $invoice->due_at->lt($this->today))->values();
    }

    public function oldestOverdue(): ?Invoice
    {
        return $this->overdue()->first();
    }

    /** أيام تأخّر أقدمها */
    public function daysLate(): int
    {
        $oldest = $this->oldestOverdue();

        return $oldest ? (int) $oldest->due_at->diffInDays($this->today) : 0;
    }

    /** الفاتورة التالية موعداً مما لم يتأخّر بعد */
    public function nextDue(): ?Invoice
    {
        return $this->open->first(fn (Invoice $invoice) => ! $invoice->due_at || ! $invoice->due_at->lt($this->today));
    }

    /**
     * يوم يتوقّف نظامها إن بقي أقدم المتأخّر بلا سداد: حين يزيد تأخّره على المهلة. null إن لم
     * تُضبط مهلة، أو أُعفيت الشركة، أو لا متأخّر، أو توقّف فعلاً.
     */
    public function suspendsOn(): ?CarbonImmutable
    {
        $grace = BillingPolicy::graceDays();
        $oldest = $this->oldestOverdue();

        if ($grace === null || $this->company->billing_exempt || ! $oldest || $this->company->isHeldForBilling()) {
            return null;
        }

        // فات يومه ولم يتوقّف بعد (مهلةٌ ضُبطت للتوّ، أو رُفع إعفاؤها): أوّل ليلةٍ تأتي
        return CarbonImmutable::parse($oldest->due_at)->addDays($grace + 1)->max($this->today->addDay());
    }

    /**
     * أيامٌ حتى تنتهي تجربتها أو اشتراكٌ لا يتجدّد — في أيام التنبيه وحدها، وسالبةٌ إن انتهى.
     * والاشتراك المتجدّد تلقائياً لا ينتهي: يتقدّم أجله كل ليلة (RenewSubscriptions).
     *
     * @return array{kind: string, days: int, ends: CarbonImmutable}|null kind: trial أو subscription
     */
    public function ending(): ?array
    {
        $window = BillingPolicy::reminderDays();

        if ($this->company->status === 'trial' && $this->company->trial_ends_at) {
            $ends = CarbonImmutable::parse($this->company->trial_ends_at)->startOfDay();
            $days = (int) $this->today->diffInDays($ends, false);

            return $days <= $window ? ['kind' => 'trial', 'days' => $days, 'ends' => $ends] : null;
        }

        $subscription = $this->subscription;

        if (! $subscription || ($subscription->auto_renew && ! $subscription->cancelled_at && $subscription->status === 'active')) {
            return null;
        }

        $ends = CarbonImmutable::parse($subscription->ends_at)->startOfDay();
        $days = (int) $this->today->diffInDays($ends, false);

        return $days <= $window ? ['kind' => 'subscription', 'days' => $days, 'ends' => $ends] : null;
    }
}
