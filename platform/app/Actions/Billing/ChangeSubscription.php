<?php

namespace App\Actions\Billing;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * اشتراك شركةٍ من لوحة المنصّة: يبدأ، أو يحلّ محلّ القائم، أو يُلغى.
 *
 * القائم يُلغى ولا يُحذف: فاتورة الشهر الذي أُلغي فيه وما قبله تُحسب به،
 * لأن GenerateInvoice يأخذ الاشتراك الساري في الفترة لا الحالي. وفي شهر
 * التغيير يغلب الأحدث.
 *
 * والسعر المتّفق عليه يُجمَّد في الاشتراك كما يُجمَّد سعر الباقة — والفارغ
 * سعرها لدورته. واشتراكٌ مدفوع لشركةٍ تجريبية ينهي تجربتها: هذا القرار
 * الذي لا يتّخذه التجديد التلقائي (RenewSubscriptions) من تلقاء نفسه.
 */
class ChangeSubscription
{
    /** ما يُعدّ اشتراكاً قائماً: ما عداه ملغى أو منتهٍ. */
    public const LIVE = ['trialing', 'active', 'past_due'];

    /**
     * @param  array{billing_cycle:string, starts_at:string, price?:int|string|null, notes?:string|null}  $data
     */
    public function start(Company $company, Plan $plan, array $data, ?User $actor = null): Subscription
    {
        $cycle = $data['billing_cycle'];
        $starts = CarbonImmutable::parse($data['starts_at'])->startOfDay();
        $price = filled($data['price'] ?? null)
            ? (int) $data['price']
            : (int) ($cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly);

        return DB::transaction(function () use ($company, $plan, $data, $actor, $cycle, $starts, $price) {
            $previous = $this->endLive($company);

            $subscription = Tenancy::runFor($company, fn () => Subscription::create([
                'plan_id'                 => $plan->id,
                'status'                  => 'active',
                'billing_cycle'           => $cycle,
                'price'                   => $price,
                'commission_per_shipment' => $plan->commission_per_shipment,
                'commission_percent'      => $plan->commission_percent,
                'starts_at'               => $starts->toDateString(),
                // بلا فيضان: اشتراكٌ يبدأ ٣١ كانون الثاني ينتهي آخر شباط لا ٣ آذار
                'ends_at'                 => ($cycle === 'yearly' ? $starts->addYearNoOverflow() : $starts->addMonthNoOverflow())
                    ->toDateString(),
                'auto_renew'              => true,
                'notes'                   => filled($data['notes'] ?? null) ? $data['notes'] : null,
            ]));

            if ($company->status === 'trial') {
                $company->forceFill(['status' => 'active', 'trial_ends_at' => null])->save();
            }

            $this->audit($company, $subscription, $actor, 'subscription_started',
                $previous->isEmpty() ? null : $this->describe($previous->last()),
                $this->describe($subscription->load('plan')),
            );

            return $subscription;
        });
    }

    /** يوقف التجديد والفوترة القادمة. الشركة نفسها تبقى تعمل حتى تُوقَف من صفحتها. */
    public function cancel(Company $company, ?User $actor = null): int
    {
        return DB::transaction(function () use ($company, $actor) {
            $ended = $this->endLive($company);

            if ($ended->isNotEmpty()) {
                $this->audit($company, $ended->last(), $actor, 'subscription_cancelled', $this->describe($ended->last()), null);
            }

            return $ended->count();
        });
    }

    /** @return Collection<int, Subscription> القائمة قبل إلغائها، الأقدم أولاً */
    protected function endLive(Company $company): Collection
    {
        $live = Subscription::acrossCompanies()
            ->where('company_id', $company->id)
            ->whereIn('status', self::LIVE)
            ->with('plan:id,name')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($live as $subscription) {
            $subscription->forceFill([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
                'auto_renew'   => false,
            ])->save();
        }

        return $live;
    }

    /** ما يُحفظ في سجلّ التدقيق: ما يُسأل عنه يوم تُراجَع فاتورة. */
    protected function describe(Subscription $subscription): array
    {
        return [
            'plan'          => $subscription->plan?->name,
            'price'         => (int) $subscription->price,
            'billing_cycle' => $subscription->billing_cycle,
            'starts_at'     => $subscription->starts_at?->toDateString(),
            'ends_at'       => $subscription->ends_at?->toDateString(),
        ];
    }

    protected function audit(Company $company, Subscription $subscription, ?User $actor, string $action, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'company_id'     => $company->id,
            'user_id'        => $actor?->id,
            'user_name'      => $actor?->name,
            'action'         => $action,
            'auditable_type' => Subscription::class,
            'auditable_id'   => $subscription->id,
            'old_values'     => $old,
            'new_values'     => $new,
            'ip'             => request()->ip(),
        ]);
    }
}
