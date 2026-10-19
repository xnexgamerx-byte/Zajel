<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * تنبيهٌ مالي (docs/plan/68 §١٢). نوعان:
 * - «حال» يبقى ما بقي سببه: يُغلَق وحده إن زال، ويعود إن عولج وسببه قائم أو زال ثم رجع.
 * - «حدث» وقع مرّةً: يبقى حتى يعالجه أحد، ولا يُغلَق وحده.
 */
class FinancialAlert extends Model
{
    use BelongsToCompany, SeenByBranch;

    /**
     * النوع: [الاسم، الخطورة الافتراضية، حدثٌ لا حال، من يعالجه (صلاحية)].
     * والرقم بجانب كلٍّ رقمه في الوثيقة.
     */
    public const TYPES = [
        'courier_cash_late'  => ['تأخّر مندوبٍ عن تسليم النقد', 'warning', false, 'batches.prepare'],     // ١
        'count_difference'   => ['المعدود يختلف عن المتوقَّع', 'critical', true, 'batches.approve'],      // ٢
        'batch_unmatched'    => ['وجبةٌ لم تُطابَق', 'warning', false, 'batches.approve'],                // ٣
        'batch_discrepancy'  => ['فروقاتٌ في وجبةٍ لم تُعالَج', 'critical', false, 'batches.approve'],     // ٤
        'merchant_overdue'   => ['مستحقّ تاجرٍ تجاوز موعده', 'warning', false, 'money.pay'],              // ٥
        'low_liquidity'      => ['انخفاض السيولة المتوقَّعة', 'critical', false, 'finance.settings'],      // ٦
        'withdrawal_over'    => ['محاولة سحبٍ فوق المتاح', 'critical', true, 'finance.settings'],         // ٧
        'duplicate_movement' => ['حركة صندوقٍ مكرّرة', 'critical', true, 'money.cash'],                   // ٨
        'post_close_change'  => ['تعديلٌ بعد الاعتماد أو الإقفال', 'info', true, 'periods.prepare'],      // ٩
        'old_custody'        => ['عهدةٌ قديمة', 'warning', false, 'batches.prepare'],                     // ١٠
        'period_pending'     => ['إقفالٌ ينتظر الاعتماد', 'info', false, 'periods.close'],                // ١١
        'double_batch'       => ['كشفٌ في وجبتين', 'critical', false, 'batches.approve'],                 // ١٢
        'remittance_diff'    => ['الواصل من فرعٍ غير المُرسَل', 'warning', true, 'money.cash'],           // ١٣
        'interbranch'        => ['الجاري بين الفروع لا يتقاصّ', 'warning', false, 'periods.prepare'],     // ١٣
        'profitability'      => ['ارتفاع التكاليف أو انخفاض الربحية', 'warning', false, 'finance.settings'], // ١٤
        'chain_broken'       => ['سلسلة البصمات مكسورة', 'critical', false, 'finance.settings'],
    ];

    /** ما يُفحص يومياً لا كل ساعة، وما يُكتب في لحظته */
    public const DAILY = ['chain_broken'];

    public const IMMEDIATE = ['withdrawal_over'];

    public const SEVERITIES = [
        'critical' => ['حرج', 'chip-bad', 4],
        'warning'  => ['تحذير', 'chip-warn', 24],
        'info'     => ['معلومة', 'chip-info', 72],
    ];

    public const STATUSES = [
        'open'        => 'مفتوح',
        'in_progress' => 'قيد المعالجة',
        'resolved'    => 'مُعالَج',
        'ignored'     => 'مُتجاهَل',
    ];

    /** المسؤول: الصلاحية بما يُعرف به صاحبها */
    public const ASSIGNEES = [
        'batches.prepare'  => 'المحاسب',
        'batches.approve'  => 'معتمد الوجبات',
        'money.pay'        => 'دافع كشوف التجّار',
        'money.cash'       => 'أمين الصندوق',
        'periods.prepare'  => 'المحاسب',
        'periods.close'    => 'صاحب الشركة',
        'finance.settings' => 'صاحب الشركة',
    ];

    public const ACTIONS = [
        'opened'    => 'فُتح',
        'escalated' => 'ارتفعت خطورته',
        'taken'     => 'تولّاه',
        'resolved'  => 'عولج',
        'ignored'   => 'تُجوهل',
        'reopened'  => 'أُعيد فتحه',
        'cleared'   => 'زال سببه',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at'  => 'datetime',
            'due_at'       => 'datetime',
            'last_seen_at' => 'datetime',
            'cleared_at'   => 'datetime',
            'resolved_at'  => 'datetime',
        ];
    }

    public static function isEvent(string $type): bool
    {
        return (bool) (self::TYPES[$type][2] ?? false);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['open', 'in_progress'], true);
    }

    public function isOverdue(): bool
    {
        return $this->isActive() && $this->due_at !== null && $this->due_at->isPast();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type][0] ?? $this->type;
    }

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity][0] ?? $this->severity;
    }

    public function severityChip(): string
    {
        return self::SEVERITIES[$this->severity][1] ?? 'chip-mute';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function assigneeLabel(): string
    {
        return $this->assignedUser?->name ?? self::ASSIGNEES[$this->assignee_ability] ?? '—';
    }

    /** رابط مصدره: الوجبة أو المندوب أو الفترة أو السحب… */
    public function link(): ?string
    {
        $id = $this->subject_id;

        return match ($this->subject_type) {
            'collection_batch'   => route('profits.batches.show', $id),
            'courier'            => route('profits.couriers.show', $id),
            'merchant'           => route('merchants.show', $id),
            'financial_period'   => route('finance.periods.show', $id),
            'journal_entry'      => route('finance.entries.show', $id),
            'cash_box'           => route('cash.index'),
            'branch_remittance'  => route('branch-accounts.remittances'),
            'cash_position'      => route('profits.cash'),
            'profits'            => route('profits.index'),
            'withdrawals'        => route('profits.withdrawals.index'),
            'trial_balance'      => route('finance.trial-balance'),
            default              => null,
        };
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(FinancialAlertAction::class)->orderBy('id');
    }
}
