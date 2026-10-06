<?php

namespace App\Actions\Billing;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Billing\BillingPolicy;
use App\Support\Billing\Dues;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;

/**
 * ما فات موعده من فواتير الشركات (docs/plan/36)، كل ليلة:
 *
 *  ١) الصادرة التي فات موعدها ولم تُسدَّد تصير «متأخّرة».
 *  ٢) شركةٌ زاد تأخّر أقدم فواتيرها على المهلة التي ضبطها صاحب المنصّة يتوقّف نظامها — ما لم
 *     تُعفَ. ولا مهلة مضبوطة: لا إيقاف. والإيقاف «بالتأخّر» لا «بيد المنصّة»: يدخل صاحبها إلى
 *     فواتيره وحدها ليدفع.
 *  ٣) وما أوقفه التأخّر يعود وحده حين لا يبقى ما يوقفه: سُدّد، أو أُعفيت، أو طالت المهلة.
 *     ويعود فور تسجيل الدفعة أيضاً (RecordPayment) لا في الليلة التالية.
 *
 * والإيقاف بيد المنصّة لا يمسّه شيءٌ هنا: يرفعه من أوقفه.
 */
class EnforceDues
{
    /** @return array{overdue: int, suspended: list<string>, resumed: list<string>} */
    public function handle(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return Tenancy::runAsPlatform(function () use ($today) {
            $overdue = Invoice::acrossCompanies()
                ->where('status', 'issued')
                ->whereColumn('amount_paid', '<', 'total')
                ->where('due_at', '<', $today->toDateString())
                ->update(['status' => 'overdue']);

            $suspended = [];
            $grace = BillingPolicy::graceDays();

            if ($grace !== null) {
                $late = Invoice::acrossCompanies()
                    ->whereIn('status', Dues::OPEN)
                    ->whereColumn('amount_paid', '<', 'total')
                    ->where('due_at', '<', $today->subDays($grace)->toDateString())
                    ->orderBy('due_at')
                    ->orderBy('id')
                    ->get()
                    ->unique('company_id');

                $companies = Company::whereIn('id', $late->pluck('company_id'))
                    ->whereIn('status', ['active', 'trial'])
                    ->where('billing_exempt', false)
                    ->get();

                foreach ($companies as $company) {
                    $this->suspend($company, $late->firstWhere('company_id', $company->id), $today);
                    $suspended[] = $company->name;
                }
            }

            $resumed = [];

            foreach (Company::where('status', 'suspended')->where('suspension_source', 'billing')->get() as $company) {
                if ($this->resume($company, null, $today)) {
                    $resumed[] = $company->name;
                }
            }

            return ['overdue' => $overdue, 'suspended' => $suspended, 'resumed' => $resumed];
        });
    }

    /**
     * أقدم فاتورةٍ زاد تأخّرها على المهلة، أو null: لا مهلة، أو معفاة، أو لا شيء فات المهلة.
     */
    public function lateInvoice(Company $company, ?CarbonImmutable $today = null): ?Invoice
    {
        $grace = BillingPolicy::graceDays();

        if ($grace === null || $company->billing_exempt) {
            return null;
        }

        return Invoice::acrossCompanies()
            ->where('company_id', $company->id)
            ->whereIn('status', Dues::OPEN)
            ->whereColumn('amount_paid', '<', 'total')
            ->where('due_at', '<', ($today ?? CarbonImmutable::today())->subDays($grace)->toDateString())
            ->orderBy('due_at')
            ->first();
    }

    /** أوقفها التأخّر ولم يبقَ ما يوقفها: تعود كما كانت. ولا يمسّ إيقافاً بيد المنصّة */
    public function resume(Company $company, ?User $by = null, ?CarbonImmutable $today = null): bool
    {
        if (! $company->isHeldForBilling() || $this->lateInvoice($company, $today) !== null) {
            return false;
        }

        $company->forceFill([
            'status'            => 'active',
            'suspended_at'      => null,
            'suspended_reason'  => null,
            'suspension_source' => null,
        ])->save();

        AuditLog::create([
            'company_id' => $company->id,
            'user_id'    => $by?->id,
            'user_name'  => $by?->name,
            'action'     => 'company_activated',
            'new_values' => ['automatic' => true, 'reason' => 'سُدّد ما تأخّر'],
        ]);

        return true;
    }

    protected function suspend(Company $company, Invoice $invoice, CarbonImmutable $today): void
    {
        $reason = "تأخّر سداد الفاتورة {$invoice->number} — يعود النظام وحده حين تُسجَّل الدفعة.";

        $company->forceFill([
            'status'            => 'suspended',
            'suspended_at'      => now(),
            'suspended_reason'  => $reason,
            'suspension_source' => 'billing',
        ])->save();

        AuditLog::create([
            'company_id' => $company->id,
            'action'     => 'company_suspended',
            'new_values' => [
                'reason'    => $reason,
                'invoice'   => $invoice->number,
                'days_late' => (int) CarbonImmutable::parse($invoice->due_at)->diffInDays($today),
                'automatic' => true,
            ],
        ]);
    }
}
