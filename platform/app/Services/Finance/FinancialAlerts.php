<?php

namespace App\Services\Finance;

use App\Actions\Settlements\BuildMerchantSettlement;
use App\Models\AuditLog;
use App\Models\CashBox;
use App\Models\CollectionBatch;
use App\Models\Courier;
use App\Models\FinancialAlert;
use App\Models\FinancialAlertAction;
use App\Models\MerchantSettlement;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Arabic;
use App\Support\Audit\AuditChain;
use App\Support\Finance\Accounts as A;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * محرّك التنبيهات المالية (docs/plan/68 §١٢). لكل نوعٍ كاشفٌ يُرجع ما يجده الآن بمفتاحٍ ثابت،
 * والمزامنة تفتح الجديد وتُصعّد ما ارتفعت خطورته، وتُغلق «الحال» الذي زال سببه وتعيد فتح ما عاد.
 * و«الحدث» لا يُغلق وحده: يبقى حتى يعالجه أحد.
 *
 * ما يُفحص كل ساعة (finance:alerts)، وسلسلتا البصمات كل يوم، ومحاولة السحب فوق المتاح في لحظتها.
 */
class FinancialAlerts
{
    /** نافذة الأحداث: ما وقع قبلها لا يُفتح له تنبيهٌ أوّل مرّة (لا يغرق التاريخ القديم الشاشة) */
    public const EVENT_WINDOW_DAYS = 14;

    public const SEVERITY_RANK = ['info' => 1, 'warning' => 2, 'critical' => 3];

    public function __construct(protected Journal $journal) {}

    // ─── الإعدادات ────────────────────────────────────────────────────────

    /** مهلة تسليم المندوب نقده بالساعات */
    public static function handoverHours(): int
    {
        return max(1, (int) (Tenancy::company()?->setting('finance.cash_handover_hours', 24) ?: 24));
    }

    /** وجبةٌ بانتظار المطابقة أكثر من هذا (ساعات) */
    public static function matchHours(): int
    {
        return max(1, (int) (Tenancy::company()?->setting('finance.batch_match_hours', 24) ?: 24));
    }

    public static function custodyDays(): int
    {
        return max(1, (int) (Tenancy::company()?->setting('finance.custody_age_days', 7) ?: 7));
    }

    /** أعلى نسبةٍ للتكاليف من الإيراد (٪) */
    public static function costRatioMax(): int
    {
        return (int) (Tenancy::company()?->setting('finance.cost_ratio_max', 90) ?? 90);
    }

    /** أدنى هامش ربح (٪): صفرٌ يعني «خسارة» */
    public static function marginMin(): int
    {
        return (int) (Tenancy::company()?->setting('finance.margin_min', 0) ?? 0);
    }

    // ─── الفحص والمزامنة ────────────────────────────────────────────────────

    /**
     * يفحص الأنواع المطلوبة (افتراضاً: كل ما يُفحص كل ساعة) ويزامن تنبيهاتها.
     *
     * @param  list<string>|null  $types
     * @return array{opened: int, cleared: int}
     */
    public function scan(?array $types = null): array
    {
        $types ??= array_values(array_diff(array_keys(FinancialAlert::TYPES), FinancialAlert::DAILY, FinancialAlert::IMMEDIATE));
        $found = collect();

        foreach ($types as $type) {
            foreach ($this->{Str::camel('detect_'.$type)}() as $candidate) {
                $found->put($candidate['key'], ['type' => $type] + $candidate);
            }
        }

        return DB::transaction(fn () => $this->sync($types, $found));
    }

    /** محاولة سحبٍ فوق المتاح: تُكتب في لحظتها، خارج معاملة الطلب المرفوض */
    public function withdrawalOverAvailable(User $actor, int $amount, int $available): void
    {
        $this->sync([], collect([[
            'type' => 'withdrawal_over', 'key' => 'withdrawal_over:'.$actor->id.':'.now()->format('YmdHis'),
            'title' => "طلب {$actor->name} سحب ".number_format($amount).' د.ع والمتاح '.number_format(max(0, $available)).' د.ع',
            'amount' => $amount, 'subject_type' => 'withdrawals',
        ]])->keyBy('key'));
    }

    /**
     * @param  list<string>  $types  ما فُحص كاملاً: ما لم يُوجد من «حاله» يُغلق
     * @param  Collection<string, array<string, mixed>>  $found
     * @return array{opened: int, cleared: int}
     */
    protected function sync(array $types, Collection $found): array
    {
        $now = now();
        $opened = 0;
        $cleared = 0;

        $existing = FinancialAlert::query()->whereIn('key', $found->keys()->all())->get()->keyBy('key');

        foreach ($found as $key => $c) {
            $type = $c['type'];
            $severity = $c['severity'] ?? FinancialAlert::TYPES[$type][1];
            $alert = $existing[$key] ?? null;
            $fields = [
                'title'        => mb_substr($c['title'], 0, 160),
                'details'      => isset($c['details']) ? mb_substr($c['details'], 0, 500) : null,
                'amount'       => $c['amount'] ?? null,
                'last_seen_at' => $now,
            ];

            if ($alert === null) {
                $occurred = $c['occurred_at'] ?? $now;
                $alert = FinancialAlert::create($fields + [
                    'type'             => $type,
                    'key'              => $key,
                    'severity'         => $severity,
                    'status'           => 'open',
                    'branch_id'        => $c['branch_id'] ?? null,
                    'courier_id'       => $c['courier_id'] ?? null,
                    'merchant_id'      => $c['merchant_id'] ?? null,
                    'subject_type'     => $c['subject_type'] ?? null,
                    'subject_id'       => $c['subject_id'] ?? null,
                    'assignee_ability' => FinancialAlert::TYPES[$type][3],
                    'occurred_at'      => $occurred,
                    'due_at'           => $this->due($severity, $now),
                ]);
                $this->act($alert, 'opened', null, null);
                $opened++;

                continue;
            }

            if (FinancialAlert::isEvent($type)) {
                continue;
            }

            if ($alert->isActive()) {
                if (self::SEVERITY_RANK[$severity] > self::SEVERITY_RANK[$alert->severity]) {
                    $fields += ['severity' => $severity, 'due_at' => $this->due($severity, $now)];
                    $this->act($alert, 'escalated', null, FinancialAlert::SEVERITIES[$severity][0]);
                }
                $alert->forceFill($fields)->save();

                continue;
            }

            // عولج وسببه قائم، أو تُجوهل ثم زال سببه وعاد: يُفتح من جديد
            if ($alert->status === 'resolved' || $alert->cleared_at !== null) {
                $note = $alert->cleared_at !== null ? 'عاد سببه بعد أن زال.' : 'ما زال سببه قائماً.';
                $alert->forceFill($fields + [
                    'status' => 'open', 'severity' => $severity, 'due_at' => $this->due($severity, $now),
                    'cleared_at' => null, 'resolved_at' => null, 'resolved_by_user_id' => null, 'assigned_user_id' => null,
                ])->save();
                $this->act($alert, 'reopened', null, $note);
                $opened++;

                continue;
            }

            $alert->forceFill(['last_seen_at' => $now])->save();
        }

        // «الحال» الذي لم يُوجد في هذا الفحص زال سببه
        $states = array_values(array_filter($types, fn ($t) => ! FinancialAlert::isEvent($t)));
        if ($states !== []) {
            $gone = FinancialAlert::query()->whereIn('type', $states)->whereNull('cleared_at')
                ->whereNotIn('key', $found->keys()->all())->get();

            foreach ($gone as $alert) {
                if ($alert->isActive()) {
                    $alert->forceFill(['status' => 'resolved', 'resolved_at' => $now, 'resolved_by_user_id' => null, 'cleared_at' => $now])->save();
                    $this->act($alert, 'cleared', null, 'زال سببه في فحص '.$now->copy()->timezone(config('app.timezone'))->format('Y-m-d H:i').'.');
                    $cleared++;
                } else {
                    $alert->forceFill(['cleared_at' => $now])->save();
                }
            }
        }

        return ['opened' => $opened, 'cleared' => $cleared];
    }

    protected function due(string $severity, Carbon $from): Carbon
    {
        return $from->copy()->addHours(FinancialAlert::SEVERITIES[$severity][2]);
    }

    // ─── ما يفعله الموظّف ─────────────────────────────────────────────────

    public function take(FinancialAlert $alert, User $actor): FinancialAlert
    {
        $this->guardActive($alert);
        $alert->forceFill(['status' => 'in_progress', 'assigned_user_id' => $actor->id])->save();
        $this->act($alert, 'taken', $actor, null);

        return $alert;
    }

    public function resolve(FinancialAlert $alert, User $actor, ?string $note): FinancialAlert
    {
        $this->guardActive($alert);
        $alert->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by_user_id' => $actor->id, 'reason' => $note])->save();
        $this->act($alert, 'resolved', $actor, $note);

        return $alert;
    }

    public function ignore(FinancialAlert $alert, User $actor, string $reason): FinancialAlert
    {
        $this->guardActive($alert);

        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب التجاهل: يبقى في سجلّ التنبيه.']);
        }

        $alert->forceFill(['status' => 'ignored', 'resolved_at' => now(), 'resolved_by_user_id' => $actor->id, 'reason' => $reason])->save();
        $this->act($alert, 'ignored', $actor, $reason);

        return $alert;
    }

    protected function guardActive(FinancialAlert $alert): void
    {
        if (! $alert->isActive()) {
            throw ValidationException::withMessages(['alert' => 'التنبيه '.$alert->statusLabel().' سلفاً.']);
        }
    }

    protected function act(FinancialAlert $alert, string $action, ?User $actor, ?string $note): void
    {
        FinancialAlertAction::create([
            'financial_alert_id' => $alert->id,
            'user_id'            => $actor?->id,
            'user_name'          => $actor?->name,
            'action'             => $action,
            'note'               => $note === null ? null : mb_substr($note, 0, 255),
            'created_at'         => now(),
        ]);

        // ما يفعله الموظّف بيده في سجلّ التدقيق؛ وما يفعله الفحص في سجلّ التنبيه وحده
        if ($actor !== null) {
            AuditLog::create([
                'user_id'        => $actor->id,
                'user_name'      => $actor->name,
                'action'         => 'financial_alert',
                'auditable_type' => FinancialAlert::class,
                'auditable_id'   => $alert->id,
                'new_values'     => ['type' => $alert->type, 'event' => $action, 'title' => $alert->title],
                'reason'         => $note === null ? null : mb_substr($note, 0, 255),
                'ip'             => request()->ip(),
            ]);
        }
    }

    // ─── الكواشف ─────────────────────────────────────────────────────────

    /** ما سلّمه المناديب ولم يُسلَّم نقده للشركة بعد: لكل مندوبٍ أقدمُه ومجموعه */
    protected function unsettled(): Collection
    {
        return Shipment::query()->toBase()
            ->whereNotNull('delivered_at')->whereNotNull('delivery_courier_id')->whereNull('courier_settlement_id')
            ->where('collected_amount', '>', 0)
            ->groupBy('delivery_courier_id')
            ->selectRaw('delivery_courier_id as courier_id, min(delivered_at) as oldest, sum(collected_amount) as cash, count(*) as n')
            ->get();
    }

    /** ١. تأخّر مندوبٍ عن تسليم النقد: أقدم ما بيده تجاوز المهلة، وحرجٌ بعد ضعفها */
    protected function detectCourierCashLate(): array
    {
        $hours = self::handoverHours();
        $rows = $this->unsettled()->filter(fn ($r) => Carbon::parse($r->oldest)->lt(now()->subHours($hours)));
        $couriers = Courier::query()->whereIn('id', $rows->pluck('courier_id'))->get(['id', 'name', 'branch_id'])->keyBy('id');

        return $rows->map(function ($r) use ($hours, $couriers) {
            $courier = $couriers[$r->courier_id] ?? null;
            $late = Carbon::parse($r->oldest)->lt(now()->subHours($hours * 2));

            return [
                'key' => 'courier_cash_late:'.$r->courier_id,
                'severity' => $late ? 'critical' : 'warning',
                'title' => ($courier?->name ?? 'مندوب').' لم يسلّم نقد '.Arabic::shipments((int) $r->n).' — أقدمها منذ '.Carbon::parse($r->oldest)->diffForHumans(null, true),
                'details' => 'مهلة التسليم '.Arabic::hours($hours).($late ? '، وتجاوز ضعفها.' : '.'),
                'amount' => (int) $r->cash, 'courier_id' => $r->courier_id, 'branch_id' => $courier?->branch_id,
                'subject_type' => 'courier', 'subject_id' => $r->courier_id,
                'occurred_at' => Carbon::parse($r->oldest)->addHours($hours),
            ];
        })->values()->all();
    }

    /** ١٠. عهدةٌ قديمة: ما بيد المندوب أقدم من حدّ الأيام */
    protected function detectOldCustody(): array
    {
        $days = self::custodyDays();
        $cutoff = now()->subDays($days);
        $rows = Shipment::query()->toBase()
            ->whereNotNull('delivered_at')->whereNotNull('delivery_courier_id')->whereNull('courier_settlement_id')
            ->where('collected_amount', '>', 0)->where('delivered_at', '<', $cutoff)
            ->groupBy('delivery_courier_id')
            ->selectRaw('delivery_courier_id as courier_id, min(delivered_at) as oldest, sum(collected_amount) as cash, count(*) as n')
            ->get();
        $couriers = Courier::query()->whereIn('id', $rows->pluck('courier_id'))->get(['id', 'name', 'branch_id'])->keyBy('id');

        return $rows->map(fn ($r) => [
            'key' => 'old_custody:'.$r->courier_id,
            'title' => 'عهدة '.($couriers[$r->courier_id]?->name ?? 'مندوب').' فيها '.Arabic::shipments((int) $r->n).' أقدم من '.Arabic::days($days),
            'details' => 'لا تُقفَل فترةٌ وفيها عهدةٌ أقدم من الحدّ (إقفال الفترات).',
            'amount' => (int) $r->cash, 'courier_id' => $r->courier_id, 'branch_id' => $couriers[$r->courier_id]?->branch_id,
            'subject_type' => 'courier', 'subject_id' => $r->courier_id,
            'occurred_at' => Carbon::parse($r->oldest)->addDays($days),
        ])->values()->all();
    }

    /** ٢. المعدود يختلف عن المتوقَّع: كل عدٍّ بفرقٍ حدثٌ — العجز حرج والزيادة تحذير */
    protected function detectCountDifference(): array
    {
        return DB::table('collection_batch_items as i')
            ->join('collection_batches as b', 'b.id', '=', 'i.collection_batch_id')
            ->leftJoin('couriers as c', 'c.id', '=', 'i.courier_id')
            ->where('i.company_id', Tenancy::id())->where('i.kind', 'settlement')->whereIn('i.status', ['short', 'over'])
            ->where('i.counted_at', '>=', now()->subDays(self::EVENT_WINDOW_DAYS))
            ->get(['i.id', 'i.received_cash', 'i.expected_cash', 'i.counted_at', 'i.courier_id', 'b.id as batch_id', 'b.number', 'b.branch_id', 'c.name'])
            ->map(function ($r) {
                $diff = (int) $r->received_cash - (int) $r->expected_cash;

                return [
                    'key' => 'count_difference:'.$r->id,
                    'severity' => $diff < 0 ? 'critical' : 'warning',
                    'title' => ($diff < 0 ? 'عجز ' : 'زيادة ').number_format(abs($diff)).' د.ع في نقد '.($r->name ?? 'مندوب').' — الوجبة '.$r->number,
                    'details' => 'المتوقَّع '.number_format((int) $r->expected_cash).' والمعدود '.number_format((int) $r->received_cash).'.',
                    'amount' => $diff, 'courier_id' => $r->courier_id, 'branch_id' => $r->branch_id,
                    'subject_type' => 'collection_batch', 'subject_id' => $r->batch_id, 'occurred_at' => Carbon::parse($r->counted_at),
                ];
            })->all();
    }

    /** ٣. وجبةٌ بانتظار المطابقة أكثر من المهلة (من آخر عدٍّ فيها) */
    protected function detectBatchUnmatched(): array
    {
        $hours = self::matchHours();

        return CollectionBatch::query()->where('status', 'awaiting_reconciliation')
            ->withMax('items as last_counted', 'counted_at')->get()
            ->filter(fn ($b) => Carbon::parse($b->last_counted ?? $b->created_at)->lt(now()->subHours($hours)))
            ->map(fn ($b) => [
                'key' => 'batch_unmatched:'.$b->id,
                'title' => "الوجبة {$b->number} بانتظار المطابقة منذ ".Carbon::parse($b->last_counted ?? $b->created_at)->diffForHumans(null, true),
                'details' => 'عُدّ نقدها ولم تُعتمد. المهلة '.Arabic::hours($hours).'.',
                'amount' => (int) $b->received_cash, 'branch_id' => $b->branch_id,
                'subject_type' => 'collection_batch', 'subject_id' => $b->id,
                'occurred_at' => Carbon::parse($b->last_counted ?? $b->created_at)->addHours($hours),
            ])->values()->all();
    }

    /** ٤. وجبةٌ فيها فروقاتٌ لم تُعالَج */
    protected function detectBatchDiscrepancy(): array
    {
        return CollectionBatch::query()->where('status', 'discrepancy')->get()
            ->map(fn ($b) => [
                'key' => 'batch_discrepancy:'.$b->id,
                'title' => "الوجبة {$b->number} فيها فرق ".number_format((int) $b->discrepancy).' د.ع لم يُعالَج',
                'details' => 'عالج كل عجزٍ ذمّةً على المندوب أو شطباً، ثم اعتمدها.',
                'amount' => (int) $b->discrepancy, 'branch_id' => $b->branch_id,
                'subject_type' => 'collection_batch', 'subject_id' => $b->id, 'occurred_at' => $b->updated_at,
            ])->values()->all();
    }

    /** ٥. مستحقّ تاجرٍ تجاوز دورة التسوية: نقده عندنا ولم يُدفع */
    protected function detectMerchantOverdue(): array
    {
        $cycle = max(1, (int) (Tenancy::company()?->setting('finance.merchant_cycle_days', 7) ?: 7));
        $cutoff = now()->subDays($cycle);

        $ready = BuildMerchantSettlement::eligibleScope(Shipment::query())
            ->where(fn ($q) => $q->where('delivered_at', '<', $cutoff)
                ->orWhere(fn ($r) => $r->whereNull('delivered_at')->where('status_changed_at', '<', $cutoff)))
            ->toBase()->groupBy('merchant_id')->selectRaw('merchant_id, sum(merchant_due) as due')->pluck('due', 'merchant_id');
        $confirmed = MerchantSettlement::query()->where('status', 'confirmed')->where('confirmed_at', '<', $cutoff)
            ->toBase()->groupBy('merchant_id')->selectRaw('merchant_id, sum(net_amount) as due')->pluck('due', 'merchant_id');

        $totals = collect($ready->keys())->merge($confirmed->keys())->unique()
            ->mapWithKeys(fn ($id) => [$id => (int) ($ready[$id] ?? 0) + (int) ($confirmed[$id] ?? 0)])
            ->filter(fn ($due) => $due > 0);
        $merchants = \App\Models\Merchant::query()->whereIn('id', $totals->keys())->get(['id', 'business_name', 'branch_id'])->keyBy('id');

        return $totals->map(fn ($due, $id) => [
            'key' => 'merchant_overdue:'.$id,
            'title' => 'مستحقّ '.($merchants[$id]?->business_name ?? 'تاجر').' '.number_format($due).' د.ع تجاوز دورة التسوية',
            'details' => 'الدورة '.Arabic::days($cycle).'. نقده عند الشركة ولم يُدفع.',
            'amount' => $due, 'merchant_id' => $id, 'branch_id' => $merchants[$id]?->branch_id,
            'subject_type' => 'merchant', 'subject_id' => $id,
        ])->values()->all();
    }

    /** ٦. انخفاض السيولة المتوقَّعة: أدنى رصيدٍ في التوقّع تحت الحدّ (أو الاحتياطي) */
    protected function detectLowLiquidity(): array
    {
        $forecast = (new CashPosition)->forecast();
        if ($forecast === null) {
            return [];
        }

        $set = Tenancy::company()?->setting('finance.min_liquidity');
        $floor = $set !== null ? (int) $set : (new ProfitDashboard)->reserve();
        $low = collect($forecast['days'])->sortBy('balance')->first();

        if ($low === null || $low['balance'] >= $floor) {
            return [];
        }

        return [[
            'key' => 'low_liquidity',
            'title' => 'الرصيد المتوقَّع ينزل إلى '.number_format($low['balance']).' د.ع يوم '.$low['date'],
            'details' => 'الحدّ الأدنى '.number_format($floor).' د.ع'.($set !== null ? '.' : ' (الاحتياطي التشغيلي).').' التوقّع في «الموقف النقدي».',
            'amount' => (int) $low['balance'], 'subject_type' => 'cash_position',
        ]];
    }

    /** ٨. حركة صندوقٍ مكرّرة: حركتان متطابقتان لمصدرٍ واحد، أو بلا مصدرٍ وبوصفٍ واحد، خلال دقيقتين */
    protected function detectDuplicateMovement(): array
    {
        $rows = DB::table('cash_movements')->where('company_id', Tenancy::id())
            ->where('created_at', '>=', now()->subDays(2))->orderBy('id')
            ->get(['id', 'cash_box_id', 'branch_id', 'direction', 'category', 'amount', 'reference_type', 'reference_id', 'description', 'created_by_user_id', 'created_at']);

        $found = [];
        $groups = $rows->groupBy(fn ($m) => implode('|', [$m->cash_box_id, $m->direction, $m->category, $m->amount, $m->created_by_user_id,
            $m->reference_type ?? '-', $m->reference_id ?? '-', $m->reference_type === null ? $m->description : '']));

        foreach ($groups as $group) {
            $group = $group->values();
            for ($i = 1; $i < $group->count(); $i++) {
                [$a, $b] = [$group[$i - 1], $group[$i]];
                if (abs(Carbon::parse($b->created_at)->diffInSeconds(Carbon::parse($a->created_at))) > 120) {
                    continue;
                }

                $box = CashBox::query()->find($a->cash_box_id, ['id', 'name']);
                $found[] = [
                    'key' => "duplicate_movement:{$a->id}:{$b->id}",
                    'title' => 'حركتان متطابقتان '.number_format((int) $a->amount).' د.ع في '.($box?->name ?? 'صندوق').' خلال دقيقتين',
                    'details' => "الحركتان #{$a->id} و#{$b->id}".($a->description ? ' — '.$a->description : '').'. إن كانت إحداهما خطأً فاعكسها بسببها.',
                    'amount' => (int) $a->amount, 'branch_id' => $a->branch_id,
                    'subject_type' => 'cash_box', 'subject_id' => $a->cash_box_id, 'occurred_at' => Carbon::parse($b->created_at),
                ];
            }
        }

        return $found;
    }

    /** ٩. تعديلٌ بعد الاعتماد أو الإقفال: قيدٌ يمسّ فترةً مُقفَلة، أو تصحيحٌ على وجبةٍ معتمدة */
    protected function detectPostCloseChange(): array
    {
        $since = now()->subDays(self::EVENT_WINDOW_DAYS);
        $entries = DB::table('journal_entries as e')->join('financial_periods as p', 'p.id', '=', 'e.adjusts_period_id')
            ->where('e.company_id', Tenancy::id())->where('e.created_at', '>=', $since)
            ->get(['e.id', 'e.amount', 'e.description', 'e.created_at', 'p.number'])
            ->map(fn ($e) => [
                'key' => 'post_close_change:entry:'.$e->id,
                'title' => 'قيدٌ يمسّ الفترة المُقفَلة '.$e->number.' بمبلغ '.number_format((int) $e->amount).' د.ع',
                'details' => ($e->description ? $e->description.' — ' : '').'كُتب بتاريخ اليوم، ويظهر في «تعديلاتٌ بعد الإقفال».',
                'amount' => (int) $e->amount, 'subject_type' => 'journal_entry', 'subject_id' => $e->id, 'occurred_at' => Carbon::parse($e->created_at),
            ]);

        $fixes = DB::table('collection_batch_audits as a')->join('collection_batches as b', 'b.id', '=', 'a.collection_batch_id')
            ->where('a.company_id', Tenancy::id())->where('a.action', 'corrected')->where('b.status', 'approved')
            ->where('a.created_at', '>=', $since)
            ->get(['a.id', 'a.subject', 'a.before', 'a.after', 'a.reason', 'a.user_name', 'a.created_at', 'b.id as batch_id', 'b.number', 'b.branch_id'])
            ->map(fn ($a) => [
                'key' => 'post_close_change:batch:'.$a->id,
                'severity' => 'warning',
                'title' => 'تصحيحٌ على الوجبة المعتمدة '.$a->number.': '.$a->subject,
                'details' => trim(($a->user_name ?? '').' — من '.($a->before ?? '—').' إلى '.($a->after ?? '—').($a->reason ? '. السبب: '.$a->reason : ''), ' —'),
                'branch_id' => $a->branch_id, 'subject_type' => 'collection_batch', 'subject_id' => $a->batch_id, 'occurred_at' => Carbon::parse($a->created_at),
            ]);

        return $entries->merge($fixes)->values()->all();
    }

    /** ١١. إقفالٌ أُعِدّ وينتظر الاعتماد */
    protected function detectPeriodPending(): array
    {
        return DB::table('financial_periods')->where('company_id', Tenancy::id())->where('status', 'pending_approval')
            ->get(['id', 'number', 'from_date', 'to_date', 'prepared_at'])
            ->map(fn ($p) => [
                'key' => 'period_pending:'.$p->id,
                'title' => "إقفال الفترة {$p->number} ينتظر الاعتماد",
                'details' => 'من '.substr($p->from_date, 0, 10).' إلى '.substr($p->to_date, 0, 10).'.',
                'subject_type' => 'financial_period', 'subject_id' => $p->id, 'occurred_at' => $p->prepared_at ? Carbon::parse($p->prepared_at) : null,
            ])->all();
    }

    /** ١٢. كشفٌ في وجبتين: لا يقع والفهرس فريد، ويُفحص */
    protected function detectDoubleBatch(): array
    {
        return DB::table('collection_batch_items as i')->join('collection_batches as b', 'b.id', '=', 'i.collection_batch_id')
            ->where('i.company_id', Tenancy::id())->whereNotNull('i.courier_settlement_id')->where('b.status', '!=', 'cancelled')
            ->groupBy('i.courier_settlement_id')->havingRaw('count(distinct i.collection_batch_id) > 1')
            ->selectRaw('i.courier_settlement_id as id, min(b.id) as batch_id')->get()
            ->map(fn ($r) => [
                'key' => 'double_batch:'.$r->id,
                'title' => 'كشف مندوبٍ (#'.$r->id.') محسوبٌ في أكثر من وجبة',
                'details' => 'نقده يُعدّ مرّتين. أخرجه من إحداهما.',
                'subject_type' => 'collection_batch', 'subject_id' => $r->batch_id,
            ])->all();
    }

    /** ١٣. تسديدٌ بين الفروع وصل بغير ما أُرسل */
    protected function detectRemittanceDiff(): array
    {
        return DB::table('branch_remittances as r')->leftJoin('branches as f', 'f.id', '=', 'r.from_branch_id')->leftJoin('branches as t', 't.id', '=', 'r.to_branch_id')
            ->where('r.company_id', Tenancy::id())->where('r.status', 'received')->whereColumn('r.received_amount', '!=', 'r.amount')
            ->where('r.received_at', '>=', now()->subDays(self::EVENT_WINDOW_DAYS))
            ->get(['r.id', 'r.number', 'r.amount', 'r.received_amount', 'r.difference_note', 'r.received_at', 'r.to_branch_id', 'f.name as from_name', 't.name as to_name'])
            ->map(fn ($r) => [
                'key' => 'remittance_diff:'.$r->id,
                'title' => "{$r->number}: أرسل {$r->from_name} ".number_format((int) $r->amount).' ووصل '.$r->to_name.' '.number_format((int) $r->received_amount),
                'details' => $r->difference_note ? 'السبب المكتوب: '.$r->difference_note : null,
                'amount' => (int) $r->received_amount - (int) $r->amount, 'branch_id' => $r->to_branch_id,
                'subject_type' => 'branch_remittance', 'subject_id' => $r->id, 'occurred_at' => Carbon::parse($r->received_at),
            ])->all();
    }

    /** ١٣. الجاري بين الفروع لا يتقاصّ في الشركة */
    protected function detectInterbranch(): array
    {
        $balance = fn (array $accounts) => (int) DB::table('journal_lines')->where('company_id', Tenancy::id())
            ->whereIn('account', $accounts)->sum(DB::raw('debit - credit'));
        $current = $balance([A::INTERBRANCH]);
        $internal = $balance([A::INTERNAL_REVENUE, A::INTERNAL_COST]);

        if ($current === 0 && $internal === 0) {
            return [];
        }

        return [[
            'key' => 'interbranch',
            'title' => 'الجاري بين الفروع (1400) لا يتقاصّ: '.number_format($current).' د.ع',
            'details' => 'عمولة الفرع الموصِّل إيرادٌ لفرعه ومصروفٌ على فرع الإنشاء، ومجموعهما في الشركة صفر. الفرق الداخلي '.number_format($internal).'.',
            'amount' => $current, 'subject_type' => 'trial_balance',
        ]];
    }

    /** ١٤. التكاليف من الإيراد فوق الحدّ، أو هامش الربح تحت الحدّ — في آخر ٣٠ يوماً */
    protected function detectProfitability(): array
    {
        if (! $this->journal->isInitialized((int) Tenancy::id())) {
            return [];
        }

        $board = new ProfitDashboard;
        $from = now()->subDays(30)->startOfDay();
        $revenue = -$board->net(ProfitDashboard::REVENUE, $from, now());
        $costs = $board->net(ProfitDashboard::COSTS, $from, now());

        if ($revenue <= 0) {
            return [];
        }

        $found = [];
        $ratio = (int) round($costs * 100 / $revenue);
        $margin = (int) round(($revenue - $costs) * 100 / $revenue);

        if ($ratio > self::costRatioMax()) {
            $found[] = [
                'key' => 'profitability:costs',
                'title' => "التكاليف {$ratio}٪ من الإيراد في آخر ٣٠ يوماً",
                'details' => 'الحدّ '.self::costRatioMax().'٪. الإيراد '.number_format($revenue).' والتكاليف '.number_format($costs).' د.ع.',
                'amount' => $costs, 'subject_type' => 'profits',
            ];
        }

        if ($margin < self::marginMin()) {
            $found[] = [
                'key' => 'profitability:margin',
                'severity' => $margin < 0 ? 'critical' : 'warning',
                'title' => ($margin < 0 ? 'خسارة' : "هامش الربح {$margin}٪").' في آخر ٣٠ يوماً',
                'details' => 'الحدّ الأدنى '.self::marginMin().'٪. الصافي '.number_format($revenue - $costs).' د.ع.',
                'amount' => $revenue - $costs, 'subject_type' => 'profits',
            ];
        }

        return $found;
    }

    /** سلسلتا البصمات: الدفتر وسجلّ التدقيق (يومياً) */
    protected function detectChainBroken(): array
    {
        $companyId = (int) Tenancy::id();
        $found = [];

        if ($at = $this->journal->brokenAt($companyId)) {
            $found[] = ['key' => 'chain_broken:journal', 'title' => "سلسلة بصمات الدفتر انكسرت عند القيد {$at}",
                'details' => 'عُدِّل قيدٌ أو حُذف خارج النظام.', 'subject_type' => 'trial_balance'];
        }

        if ($at = AuditChain::brokenAt($companyId)) {
            $found[] = ['key' => 'chain_broken:audit', 'title' => "سلسلة سجلّ التدقيق انكسرت عند السطر {$at}",
                'details' => 'عُدِّل سطرٌ أو حُذف من سجلّ التدقيق خارج النظام.', 'subject_type' => 'trial_balance'];
        }

        return $found;
    }

    /** علامة «فُحص منذ قليل» للشركة: ما دامت قائمةً لا تفحص الشاشة بنفسها */
    public static function scanKey(int $companyId): string
    {
        return 'finance-alerts-scan:'.$companyId;
    }

    /** الشاشة ترى ما يحتاج فحصاً قبل أن يأتي موعد الساعة: فحصٌ إن مرّ على آخره ربع ساعة */
    public function scanIfStale(): void
    {
        if (cache()->add(self::scanKey((int) Tenancy::id()), true, now()->addMinutes(15))) {
            $this->scan();
        }
    }

    /** @return array<string, int> المفتوح بخطورته لمن يرى */
    public function counts(?User $viewer): array
    {
        return FinancialAlert::query()->visibleTo($viewer)->whereIn('status', ['open', 'in_progress'])
            ->groupBy('severity')->selectRaw('severity, count(*) as n')->pluck('n', 'severity')->map(fn ($n) => (int) $n)->all();
    }
}
