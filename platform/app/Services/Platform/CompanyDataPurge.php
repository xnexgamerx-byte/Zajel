<?php

namespace App\Services\Platform;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * حذف بيانات شركةٍ من لوحة النواة (docs/plan/102): أجزاءٌ يختارها مدير المنصّة — الشحنات، والطلبات،
 * والرسائل، والمال، والتجّار، والمناديب، والموظّفون — في كل الوقت أو يومٍ أو مدّة.
 *
 * يحذف حذفاً حقيقياً بالمعرّفات وبـ company_id في كل سطر، فلا يمسّ شركةً أخرى ولا يمرّ بأحداث النماذج.
 * يبقى دائماً: الشركة وصاحبها، وإعداداتها وفروعها وأسعارها ومناطقها، واشتراكها وفواتيرها مع المنصّة،
 * وسجلّ التدقيق (سلسلة بصماتٍ لا تُكسر؛ والحذف نفسه يُكتب فيه).
 *
 * وما بقي يبقى سليماً: التاجر أو المندوب الذي بقيت له شحناتٌ أو حركاتُ مال لا يُحذف، وبعد حذف المال
 * تُعاد الأرصدة من الحركات الباقية، ويُعاد تسلسل دفتر القيود وبصماته وملخّصه اليوميّ.
 */
final class CompanyDataPurge
{
    /** الأجزاء بترتيب الشاشة: [الاسم، ما يشمله] */
    public const PARTS = [
        'shipments' => ['الشحنات', 'الشحنات وحركاتها وتذاكرها ومتابعاتها، والأكياس والمنافيست.'],
        'requests' => ['الطلبات', 'طلبات الاستلام، وطلبات التجّار، ودفعات الراجع ووصولاته، وحصص الاستلام.'],
        'messages' => ['الرسائل', 'محادثات التجّار، ورسائل المناديب، ورسائل الموظّفين، والإعلانات.'],
        'money' => ['الحسابات والمال', 'حركات الحسابات والصناديق، والكشوف ووجبات التحصيل، والمصاريف والسلف والأمانات، وقيود الدفتر. وتُعاد الأرصدة من الباقي.'],
        'merchants' => ['التجّار', 'التجّار وحسابات دخولهم. من بقيت له شحناتٌ أو حركات مال يبقى.'],
        'couriers' => ['المناديب', 'المناديب وحسابات دخولهم ومناطقهم. من بقيت له كشوفٌ أو حركات مال يبقى.'],
        'staff' => ['الموظّفون', 'حسابات الموظّفين كلّها إلّا صاحب الشركة.'],
    ];

    /** أسماء ما حُذف في النتيجة وفي سجلّ التدقيق */
    public const COUNTED = [
        'shipments' => 'شحنة', 'bags' => 'كيس', 'manifests' => 'منافيست',
        'pickup_requests' => 'طلب استلام', 'merchant_requests' => 'طلب تاجر', 'return_batches' => 'دفعة راجع', 'courier_return_receipts' => 'وصل راجع',
        'conversation_messages' => 'رسالة تاجر', 'courier_messages' => 'رسالة مندوب', 'staff_messages' => 'رسالة موظّف', 'announcements' => 'إعلان',
        'transactions' => 'حركة حساب', 'cash_movements' => 'حركة صندوق', 'courier_settlements' => 'كشف مندوب', 'merchant_settlements' => 'كشف تاجر',
        'collection_batches' => 'وجبة تحصيل', 'merchant_advances' => 'سلفة', 'expenses' => 'مصروف', 'journal_entries' => 'قيد',
        'merchants' => 'تاجر', 'couriers' => 'مندوب', 'staff' => 'موظّف', 'conversations' => 'محادثة',
        'kept_merchants' => 'تاجر بقي لشحناته أو ماله', 'kept_couriers' => 'مندوب بقي لكشوفه أو ماله',
    ];

    /** ترتيب الحذف: ما يتعلّق بغيره أوّلاً، والتاجر والمندوب بعد شحناتهم ومالهم */
    private const ORDER = ['requests', 'messages', 'shipments', 'money', 'merchants', 'couriers', 'staff'];

    /** أدوار الموظّفين التي تُحذف — صاحب الشركة يبقى ليدخل نظامه */
    private const STAFF_ROLES = [
        UserRole::CompanyAdmin, UserRole::BranchOwner, UserRole::BranchManager,
        UserRole::Operations, UserRole::CustomerService, UserRole::Accountant,
    ];

    private const CHUNK = 500;

    /** ملفّاتٌ تُمسح من القرص بعد أن ينجح الحذف */
    private array $files = [];

    /** @var array<string, int> */
    private array $counts = [];

    private ?string $from;

    private ?string $to;

    /** null = كل الوقت */
    public function __construct(
        private readonly Company $company,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ) {
        $this->from = $from?->startOfDay()->toDateTimeString();
        $this->to = ($to ?? $from)?->endOfDay()->toDateTimeString();
    }

    public function allTime(): bool
    {
        return $this->from === null;
    }

    /**
     * ما سيُحذف قبل التأكيد: لكل جزءٍ سطوره بأسمائها وأعدادها.
     *
     * @param  list<string>  $parts
     * @return array<string, list<array{0: string, 1: int}>>
     */
    public function preview(array $parts): array
    {
        $rows = [
            'shipments' => [['شحنة', $this->scoped('shipments')->count()], ['كيس', $this->scoped('bags')->count()], ['منافيست', $this->scoped('manifests')->count()]],
            'requests' => [['طلب استلام', $this->scoped('pickup_requests')->count()], ['طلب تاجر', $this->scoped('merchant_requests')->count()],
                ['دفعة راجع', $this->scoped('return_batches')->count()], ['وصل راجع', $this->scoped('courier_return_receipts')->count()]],
            'messages' => [['رسالة تاجر', $this->scoped('conversation_messages')->count()], ['رسالة مندوب', $this->scoped('courier_messages')->count()],
                ['رسالة موظّف', $this->scoped('staff_messages')->count()], ['إعلان', $this->scoped('announcements')->count()]],
            'money' => [['حركة حساب', $this->scoped('transactions')->count()], ['حركة صندوق', $this->scoped('cash_movements')->count()],
                ['كشف', $this->scoped('courier_settlements')->count() + $this->scoped('merchant_settlements')->count()],
                ['وجبة تحصيل', $this->scoped('collection_batches')->count()], ['مصروف', $this->scoped('expenses')->count()],
                ['قيد', $this->journalScope()->count()]],
            'merchants' => [['تاجر', $this->scoped('merchants')->count()]],
            'couriers' => [['مندوب', $this->scoped('couriers')->count()]],
            'staff' => [['موظّف', $this->staffQuery()->count()]],
        ];

        return array_intersect_key($rows, array_flip($parts));
    }

    /**
     * يحذف الأجزاء كلّها في معاملةٍ واحدة: إمّا كلّها أو لا شيء.
     *
     * @param  list<string>  $parts
     * @return array<string, int> ما حُذف (ومن بقي من التجّار والمناديب: kept_*)
     */
    public function run(array $parts): array
    {
        @set_time_limit(0);

        DB::transaction(function () use ($parts) {
            foreach (self::ORDER as $part) {
                if (in_array($part, $parts, true)) {
                    $this->{$part}();
                }
            }

            if (in_array('money', $parts, true)) {
                $this->rebuildBalances();
                $this->rechainJournal();
            }
        });

        // القرص بعد المعاملة: ملفٌّ مُسح وحذفٌ تراجع لا يجتمعان
        foreach (array_unique(array_filter($this->files)) as $path) {
            Storage::disk('local')->delete($path);
        }

        return array_filter($this->counts);
    }

    // ── تاجرٌ واحد (docs/plan/108) ──────────────────────────────────────────

    /**
     * ما يمنع حذف التاجر وحده: مالٌ اختلط بمال غيره، فحذفه يغيّر كشفاً أو حساباً حقيقياً. يُحذف حينها مع
     * «الحسابات والمال» للشركة، أو تُلغى كشوفه أوّلاً.
     *
     * @return list<string>
     */
    public function merchantBlockers(int $merchantId): array
    {
        $shipments = $this->merchantShipments($merchantId);
        $inCourierSettlements = 0;
        foreach ($shipments->chunk(self::CHUNK) as $chunk) {
            $inCourierSettlements += DB::table('courier_settlement_shipments')->whereIn('shipment_id', $chunk->values())->count();
        }

        $blockers = array_filter([
            $this->table('merchant_settlements')->where('merchant_id', $merchantId)->count() => 'كشف تاجر',
            $inCourierSettlements => 'من شحناته في كشوف مناديب (مع شحنات تجّارٍ آخرين)',
            $this->table('merchant_advances')->where('merchant_id', $merchantId)->count() => 'سلفة',
            $this->table('merchant_deposits')->where('merchant_id', $merchantId)->count() => 'حركة أمانة',
            $this->table('prepaid_receipts')->where('merchant_id', $merchantId)->count() => 'وصل أجورٍ مقدّمة',
            $this->mixedJournalEntries($merchantId) => 'قيد في الدفتر يجمعه بغيره',
        ], fn ($label, $n) => $n > 0, ARRAY_FILTER_USE_BOTH);

        return array_map(fn ($n, $label) => number_format($n).' '.$label, array_keys($blockers), $blockers);
    }

    /** @return list<array{0: string, 1: int}> ما سيُحذف للتاجر */
    public function merchantPreview(int $merchantId): array
    {
        $shipments = $this->merchantShipments($merchantId);

        return [
            ['شحنة', $shipments->count()],
            ['طلب استلام', $this->table('pickup_requests')->where('merchant_id', $merchantId)->count()],
            ['طلب تاجر', $this->table('merchant_requests')->where('merchant_id', $merchantId)->count()],
            ['دفعة راجع', $this->table('return_batches')->where('merchant_id', $merchantId)->count()],
            ['رسالة', $this->table('conversation_messages')->whereIn('conversation_id', $this->table('conversations')->where('merchant_id', $merchantId)->select('id'))->count()],
            ['حركة حساب', $this->merchantTransactions($merchantId)->count()],
            ['قيد', $this->merchantJournalEntries($merchantId)->count()],
            ['حساب دخول', $this->table('users')->where('merchant_id', $merchantId)->count()],
        ];
    }

    /**
     * يحذف التاجر وكلّ ما له في معاملةٍ واحدة: شحناته وأحداثها وتذاكرها، وطلباته ودفعات راجعه، ومحادثاته
     * وإعلاناته، وحركات حسابه وحركات شحناته وقيودها، وحسابات دخوله ودفاتر وصولاته. ثم تُعاد الأرصدة ويُعاد
     * تسلسل الدفتر. لا يُحذف شيءٌ إن منعه مانع (merchantBlockers). حصص المناديب عن استلام شحناته تبقى:
     * استحقّوها بالذهاب، وقد تكون صُرفت.
     *
     * @return array<string, int>
     */
    public function purgeMerchant(int $merchantId): array
    {
        if ($this->merchantBlockers($merchantId) !== []) {
            throw new \RuntimeException('Merchant money is mixed with others; purge refused.');
        }

        @set_time_limit(0);

        DB::transaction(function () use ($merchantId) {
            $shipments = $this->merchantShipments($merchantId);

            // الطلبات
            $pickups = $this->table('pickup_requests')->where('merchant_id', $merchantId)->pluck('id');
            $this->nullIn('pickup_shares', 'pickup_request_id', $pickups);
            $this->nullIn('shipments', 'pickup_request_id', $pickups);
            $this->count('pickup_requests', $this->deleteIn('pickup_requests', 'id', $pickups));
            $this->count('merchant_requests', $this->table('merchant_requests')->where('merchant_id', $merchantId)->delete());
            $batches = $this->table('return_batches')->where('merchant_id', $merchantId)->pluck('id');
            $this->nullIn('shipments', 'return_batch_id', $batches);
            $this->count('return_batches', $this->deleteIn('return_batches', 'id', $batches));

            // الرسائل والإعلانات
            $conversations = $this->table('conversations')->where('merchant_id', $merchantId)->pluck('id');
            $this->files = [...$this->files, ...$this->table('conversation_messages')->whereIn('conversation_id', $conversations)
                ->whereNotNull('attachment_path')->pluck('attachment_path')->all()];
            $this->count('conversation_messages', $this->deleteIn('conversation_messages', 'conversation_id', $conversations));
            $this->deleteIn('conversations', 'id', $conversations);
            $announcements = $this->table('announcements')->where('merchant_id', $merchantId)->pluck('id');
            $this->deleteIn('announcement_reads', 'announcement_id', $announcements);
            $this->count('announcements', $this->deleteIn('announcements', 'id', $announcements));

            // المال: حركات حسابه وحركات شحناته، وقيودها كاملةً (لا يجمعها قيدٌ بغيره — merchantBlockers)
            $entries = $this->merchantJournalEntries($merchantId)->pluck('id');
            $this->count('transactions', $this->merchantTransactions($merchantId)->delete());
            $this->nullIn('financial_periods', 'closing_entry_id', $entries);
            $this->deleteIn('journal_lines', 'journal_entry_id', $entries);
            $this->count('journal_entries', $this->deleteIn('journal_entries', 'id', $entries));

            // الشحنات وما يتبعها
            foreach (['shipment_events', 'shipment_tickets', 'shipment_follow_ups', 'bag_shipments', 'aggregator_jobs', 'aggregator_events'] as $table) {
                $this->deleteIn($table, 'shipment_id', $shipments);
            }
            foreach (['staff_messages', 'courier_messages', 'conversations'] as $table) {
                $this->nullIn($table, 'shipment_id', $shipments);
            }
            $this->nullIn('shipments', 'duplicate_of_id', $shipments);
            $this->nullIn('shipments', 'replacement_for_id', $shipments);
            $this->count('shipments', $this->deleteIn('shipments', 'id', $shipments));

            // التاجر نفسه
            $this->files = [...$this->files, ...$this->table('merchants')->where('id', $merchantId)->pluck('logo_path')->all()];
            $this->deleteUsers($this->table('users')->where('merchant_id', $merchantId)->pluck('id'));
            $this->deleteIn('waybill_books', 'merchant_id', collect([$merchantId]));
            $this->count('merchants', $this->table('merchants')->where('id', $merchantId)->delete());

            $this->rebuildBalances();
            $this->rechainJournal();
        });

        foreach (array_unique(array_filter($this->files)) as $path) {
            Storage::disk('local')->delete($path);
        }

        return array_filter($this->counts);
    }

    private function merchantShipments(int $merchantId): Collection
    {
        return $this->shipmentsOf($merchantId)->pluck('id');
    }

    /** معرّفات شحناته استعلاماً فرعياً: لا قائمةً تطول بعدد شحناته */
    private function shipmentsOf(int $merchantId): Builder
    {
        return $this->table('shipments')->where('merchant_id', $merchantId)->select('id');
    }

    private function merchantTransactions(int $merchantId): Builder
    {
        return $this->table('transactions')->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('account_type', 'merchant')->where('account_id', $merchantId))
            ->orWhereIn('shipment_id', $this->shipmentsOf($merchantId)));
    }

    /** قيودٌ في سطرٍ منها شحنةٌ له أو حسابه */
    private function merchantJournalEntries(int $merchantId): Builder
    {
        return $this->table('journal_entries')->whereIn('id', $this->table('journal_lines')
            ->where(fn ($q) => $q->where('merchant_id', $merchantId)->orWhereIn('shipment_id', $this->shipmentsOf($merchantId)))
            ->select('journal_entry_id'));
    }

    /** من قيوده ما فيه سطرٌ لشحنةٍ أو تاجرٍ غيره: لا يُحذف القيد كلّه ولا يُبتر */
    private function mixedJournalEntries(int $merchantId): int
    {
        return $this->merchantJournalEntries($merchantId)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('journal_lines as o')->whereColumn('o.journal_entry_id', 'journal_entries.id')
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->whereNotNull('o.merchant_id')->where('o.merchant_id', '!=', $merchantId))
                    ->orWhere(fn ($q) => $q->whereNotNull('o.shipment_id')->whereNotIn('o.shipment_id', $this->shipmentsOf($merchantId)))))
            ->count();
    }

    // ── الأجزاء ────────────────────────────────────────────────────────────

    private function shipments(): void
    {
        $ids = $this->scoped('shipments')->pluck('id');

        // ربطها بالكشوف يمنع حذفها (RESTRICT)، والباقي يتبعها
        foreach (['courier_settlement_shipments', 'merchant_settlement_shipments', 'shipment_events', 'shipment_tickets',
            'shipment_follow_ups', 'bag_shipments'] as $table) {
            $this->deleteIn($table, 'shipment_id', $ids);
        }
        $this->nullIn('shipments', 'duplicate_of_id', $ids);
        $this->nullIn('shipments', 'replacement_for_id', $ids);
        $this->count('shipments', $this->deleteIn('shipments', 'id', $ids));

        $bags = $this->scoped('bags')->pluck('id');
        $manifests = $this->scoped('manifests')->pluck('id');
        $this->deleteIn('manifest_bags', 'manifest_id', $manifests);
        $this->deleteIn('manifest_bags', 'bag_id', $bags);
        $this->deleteIn('bag_shipments', 'bag_id', $bags);
        $this->nullIn('shipments', 'current_bag_id', $bags);
        $this->count('bags', $this->deleteIn('bags', 'id', $bags));
        $this->count('manifests', $this->deleteIn('manifests', 'id', $manifests));
    }

    private function requests(): void
    {
        $pickups = $this->scoped('pickup_requests')->pluck('id');
        $this->deleteIn('pickup_shares', 'pickup_request_id', $pickups);
        $this->scoped('pickup_shares')->delete();
        $this->nullIn('shipments', 'pickup_request_id', $pickups);
        $this->count('pickup_requests', $this->deleteIn('pickup_requests', 'id', $pickups));

        $this->count('merchant_requests', $this->scoped('merchant_requests')->delete());

        $batches = $this->scoped('return_batches')->pluck('id');
        $this->nullIn('shipments', 'return_batch_id', $batches);
        $this->nullIn('merchant_requests', 'return_batch_id', $batches);
        $this->count('return_batches', $this->deleteIn('return_batches', 'id', $batches));

        $receipts = $this->scoped('courier_return_receipts')->pluck('id');
        $this->nullIn('shipments', 'courier_return_receipt_id', $receipts);
        $this->count('courier_return_receipts', $this->deleteIn('courier_return_receipts', 'id', $receipts));
    }

    private function messages(): void
    {
        $this->files = [...$this->files, ...$this->scoped('conversation_messages')->whereNotNull('attachment_path')->pluck('attachment_path')->all()];

        // الرسائل في المدّة، ثم كل محادثةٍ لم يبقَ فيها شيء
        foreach ([['conversation_messages', 'conversations', 'conversation_id'], ['courier_messages', 'courier_threads', 'courier_thread_id'],
            ['staff_messages', 'staff_threads', 'staff_thread_id']] as [$messages, $threads, $key]) {
            $touched = $this->scoped($messages)->distinct()->pluck($key)->merge($this->scoped($threads)->pluck('id'))->unique()->values();
            $this->count($messages, $this->scoped($messages)->delete());

            $empty = collect();
            foreach ($touched->chunk(self::CHUNK) as $chunk) {
                $empty = $empty->merge($this->table($threads)->whereIn('id', $chunk->values())
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($messages)->whereColumn("$messages.$key", "$threads.id"))
                    ->pluck('id'));
            }

            if ($threads === 'staff_threads') {
                $this->deleteIn('staff_thread_members', 'staff_thread_id', $empty, scoped: false);
                // آخر رسالةٍ في المحادثة الباقية من الباقي
                $this->table('staff_threads')->whereIn('id', $touched->diff($empty)->values())->update([
                    'last_message_id' => DB::raw('(select max(id) from staff_messages where staff_messages.staff_thread_id = staff_threads.id)'),
                ]);
            }

            $this->deleteIn($threads, 'id', $empty);
        }

        $announcements = $this->scoped('announcements')->pluck('id');
        $this->deleteIn('announcement_reads', 'announcement_id', $announcements);
        $this->count('announcements', $this->deleteIn('announcements', 'id', $announcements));
    }

    private function money(): void
    {
        // وجبات التحصيل وما تحتها (تكاليفها تمنع حذف مصاريفها، وسطورها تمنع حذف الكشوف)
        $batches = $this->scoped('collection_batches')->pluck('id');
        $this->files = [...$this->files, ...$this->table('collection_batch_files')->whereIn('collection_batch_id', $batches)->pluck('path')->all()];
        foreach (['collection_batch_audits', 'collection_batch_costs', 'collection_batch_files'] as $table) {
            $this->deleteIn($table, 'collection_batch_id', $batches);
        }
        $this->deleteBatchItems($this->table('collection_batch_items')->whereIn('collection_batch_id', $batches)->pluck('id'));
        $this->count('collection_batches', $this->deleteIn('collection_batches', 'id', $batches));

        $settlements = $this->scoped('courier_settlements')->pluck('id');
        $this->deleteBatchItems($this->table('collection_batch_items')->whereIn('courier_settlement_id', $settlements)->pluck('id'));
        $this->deleteIn('courier_settlement_shipments', 'courier_settlement_id', $settlements);
        $this->clearIn('shipments', 'courier_settlement_id', $settlements, ['courier_settlement_id' => null, 'courier_settled_at' => null]);
        $this->count('courier_settlements', $this->deleteIn('courier_settlements', 'id', $settlements));

        $settlements = $this->scoped('merchant_settlements')->pluck('id');
        $this->deleteIn('merchant_settlement_shipments', 'merchant_settlement_id', $settlements);
        $this->clearIn('shipments', 'merchant_settlement_id', $settlements, ['merchant_settlement_id' => null, 'merchant_settled_at' => null]);
        $this->nullIn('merchant_requests', 'merchant_settlement_id', $settlements);
        $this->nullIn('merchant_advance_recoveries', 'merchant_settlement_id', $settlements);
        $this->count('merchant_settlements', $this->deleteIn('merchant_settlements', 'id', $settlements));

        $advances = $this->scoped('merchant_advances')->pluck('id');
        $this->deleteIn('merchant_advance_recoveries', 'merchant_advance_id', $advances);
        $this->scoped('merchant_advance_recoveries')->delete();
        $this->count('merchant_advances', $this->deleteIn('merchant_advances', 'id', $advances));

        $expenses = $this->scoped('expenses')->pluck('id');
        $this->deleteIn('collection_batch_costs', 'expense_id', $expenses);
        $this->count('expenses', $this->deleteIn('expenses', 'id', $expenses));

        $receipts = $this->scoped('prepaid_receipts')->pluck('id');
        $this->nullIn('shipments', 'prepaid_receipt_id', $receipts);
        $this->deleteIn('prepaid_receipts', 'id', $receipts);

        $alerts = $this->scoped('financial_alerts')->pluck('id');
        $this->deleteIn('financial_alert_actions', 'financial_alert_id', $alerts);
        $this->deleteIn('financial_alerts', 'id', $alerts);

        $periods = $this->scoped('financial_periods')->pluck('id');
        $this->deleteIn('financial_period_reports', 'financial_period_id', $periods);
        $this->nullIn('journal_entries', 'adjusts_period_id', $periods);
        $this->deleteIn('financial_periods', 'id', $periods);

        foreach (['merchant_deposits', 'pickup_payouts', 'profit_withdrawals', 'branch_remittances', 'financial_snapshots'] as $table) {
            $this->scoped($table)->delete();
        }
        $this->count('transactions', $this->scoped('transactions')->delete());
        $this->count('cash_movements', $this->scoped('cash_movements')->delete());

        $entries = $this->journalScope()->pluck('id');
        $this->nullIn('financial_periods', 'closing_entry_id', $entries);
        $this->deleteIn('journal_lines', 'journal_entry_id', $entries);
        $this->count('journal_entries', $this->deleteIn('journal_entries', 'id', $entries));
    }

    private function merchants(): void
    {
        $candidates = $this->scoped('merchants')->pluck('id');
        $kept = $this->stillUsed($candidates, [
            ['shipments', 'merchant_id'], ['merchant_settlements', 'merchant_id'], ['prepaid_receipts', 'merchant_id'],
            ['merchant_deposits', 'merchant_id'], ['merchant_advances', 'merchant_id'],
        ], 'merchant');
        $ids = $candidates->diff($kept)->values();

        $this->files = [...$this->files, ...$this->table('merchants')->whereIn('id', $ids)->pluck('logo_path')->all()];
        $this->deleteUsers($this->table('users')->whereIn('merchant_id', $ids)->pluck('id'));
        // دفاتر الوصولات تمنع حذفه (RESTRICT)، والباقي يتبعه
        $books = $this->table('waybill_books')->whereIn('merchant_id', $ids)->pluck('id');
        $this->nullIn('shipments', 'waybill_book_id', $books);
        $this->deleteIn('waybill_books', 'id', $books);
        $this->count('merchants', $this->deleteIn('merchants', 'id', $ids));
        $this->count('kept_merchants', $kept->count());
    }

    private function couriers(): void
    {
        $candidates = $this->scoped('couriers')->pluck('id');
        $kept = $this->stillUsed($candidates, [
            ['courier_settlements', 'courier_id'], ['pickup_payouts', 'courier_id'],
        ], 'courier');
        $ids = $candidates->diff($kept)->values();

        $this->deleteUsers($this->table('users')->whereIn('courier_id', $ids)->pluck('id')
            ->merge($this->table('couriers')->whereIn('id', $ids)->whereNotNull('user_id')->pluck('user_id')));

        $threads = $this->table('courier_threads')->whereIn('courier_id', $ids)->pluck('id');
        $this->deleteIn('courier_messages', 'courier_thread_id', $threads);
        $this->deleteIn('courier_threads', 'id', $threads);
        $this->nullIn('couriers', 'parent_id', $ids);
        foreach (['courier_zones', 'courier_api_keys', 'pickup_shares'] as $table) {
            $this->deleteIn($table, 'courier_id', $ids);
        }
        $this->count('couriers', $this->deleteIn('couriers', 'id', $ids));
        $this->count('kept_couriers', $kept->count());
    }

    private function staff(): void
    {
        $this->count('staff', $this->deleteUsers($this->staffQuery()->pluck('id')));
    }

    // ── بعد حذف المال ───────────────────────────────────────────────────────

    /** الأرصدة تسريع قراءةٍ للحركات (Ledger، CashBook): تُعاد من الباقي منها */
    private function rebuildBalances(): void
    {
        $c = $this->company->id;
        $signed = "sum(case when direction = 'credit' then amount else -amount end)";
        $commission = "category in ('commission', 'commission_paid')";

        $this->table('merchants')->update([
            'balance' => DB::raw("coalesce((select $signed from transactions where company_id = $c and account_type = 'merchant' and account_id = merchants.id), 0)"),
            'deposit_balance' => DB::raw("coalesce((select sum(case when direction = 'in' then amount else -amount end) from merchant_deposits where company_id = $c and merchant_id = merchants.id), 0)"),
        ]);
        $this->table('couriers')->update([
            'cash_in_hand' => DB::raw("coalesce((select -$signed from transactions where company_id = $c and account_type = 'courier' and account_id = couriers.id and not $commission), 0)"),
            'commission_balance' => DB::raw("coalesce((select $signed from transactions where company_id = $c and account_type = 'courier' and account_id = couriers.id and $commission), 0)"),
        ]);
        $this->table('cash_boxes')->update([
            'balance' => DB::raw("coalesce((select sum(case when direction = 'in' then amount else -amount end) from cash_movements where company_id = $c and cash_box_id = cash_boxes.id), 0)"),
        ]);
        $this->table('merchant_advances')->update([
            'recovered' => DB::raw("coalesce((select sum(amount) from merchant_advance_recoveries where company_id = $c and merchant_advance_id = merchant_advances.id), 0)"),
        ]);
    }

    /**
     * الدفتر سلسلة بصمات (Journal::brokenAt): ما بقي يُرقَّم من جديد بترتيبه وتُعاد بصماته بالصيغة نفسها،
     * ويُبنى ملخّصه اليوميّ من سطوره، فيسلم الفحص بعد الحذف.
     */
    private function rechainJournal(): void
    {
        $c = $this->company->id;
        $previous = null;
        $number = 0;

        // الترقيم ينزل ولا يصعد: الرقم الجديد خالٍ دائماً، والقطعة التالية بعد آخر رقمٍ قديم
        DB::table('journal_entries')->where('company_id', $c)
            ->select(['id', 'number', 'idempotency_key', 'entry_date', 'prev_hash', 'hash'])
            ->chunkById(1000, function ($entries) use (&$previous, &$number) {
                $lines = DB::table('journal_lines')->whereIn('journal_entry_id', $entries->pluck('id'))->orderBy('id')
                    ->get(['journal_entry_id', 'account', 'debit', 'credit'])->groupBy('journal_entry_id');

                foreach ($entries as $entry) {
                    $number++;
                    $hash = hash('sha256', implode('|', [
                        $previous, $number, $entry->idempotency_key, substr((string) $entry->entry_date, 0, 10),
                        json_encode(($lines[$entry->id] ?? collect())->map(fn ($l) => [$l->account, (int) $l->debit, (int) $l->credit])->values()->all()),
                    ]));

                    if ((int) $entry->number !== $number || $entry->prev_hash !== $previous || $entry->hash !== $hash) {
                        DB::table('journal_entries')->where('id', $entry->id)->update(['number' => $number, 'prev_hash' => $previous, 'hash' => $hash]);
                    }
                    $previous = $hash;
                }
            }, 'number');

        DB::table('journal_heads')->where('company_id', $c)->update([
            'last_number' => $number, 'last_hash' => $previous, 'updated_at' => now(),
            'verified_number' => null, 'verified_hash' => null, 'verified_at' => null, 'broken_number' => null,
        ]);

        DB::table('journal_daily')->where('company_id', $c)->delete();
        DB::table('journal_daily')->insertUsing(
            ['company_id', 'entry_date', 'account', 'branch_id', 'closing', 'debit', 'credit'],
            DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('l.company_id', $c)
                ->selectRaw("l.company_id, l.entry_date, l.account, coalesce(l.branch_id, 0) as branch, case when e.event = 'period_close' then 1 else 0 end as closing, sum(l.debit), sum(l.credit)")
                ->groupBy('l.company_id', 'l.entry_date', 'l.account', 'branch', 'closing'),
        );
    }

    // ── أدوات ──────────────────────────────────────────────────────────────

    private function table(string $table): Builder
    {
        return DB::table($table)->where("$table.company_id", $this->company->id);
    }

    /** سطور الشركة في المدّة المختارة (بتاريخ إنشائها) */
    private function scoped(string $table): Builder
    {
        return $this->table($table)->when($this->from, fn ($q) => $q->whereBetween("$table.created_at", [$this->from, $this->to]));
    }

    /** القيود بتاريخها المحاسبيّ: به كُتبت من مصدرها */
    private function journalScope(): Builder
    {
        return $this->table('journal_entries')
            ->when($this->from, fn ($q) => $q->whereBetween('entry_date', [substr($this->from, 0, 10), substr($this->to, 0, 10)]));
    }

    private function staffQuery(): Builder
    {
        return $this->scoped('users')->whereIn('role', array_map(fn (UserRole $r) => $r->value, self::STAFF_ROLES));
    }

    private function deleteIn(string $table, string $column, Collection $ids, bool $scoped = true): int
    {
        $deleted = 0;
        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            $deleted += ($scoped ? $this->table($table) : DB::table($table))->whereIn($column, $chunk->values())->delete();
        }

        return $deleted;
    }

    private function nullIn(string $table, string $column, Collection $ids): void
    {
        $this->clearIn($table, $column, $ids, [$column => null]);
    }

    private function clearIn(string $table, string $column, Collection $ids, array $values): void
    {
        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            $this->table($table)->whereIn($column, $chunk->values())->update($values);
        }
    }

    /** سطور وجباتٍ يُشير بعضها إلى بعض (remainder_of_item_id): تُفكّ ثم تُحذف */
    private function deleteBatchItems(Collection $ids): void
    {
        $this->nullIn('collection_batch_items', 'remainder_of_item_id', $ids);
        $this->deleteIn('collection_batch_items', 'id', $ids);
    }

    /**
     * من بقيت له سطورٌ تمنع حذفه أو حركاتُ مال في حسابه.
     *
     * @param  list<array{0: string, 1: string}>  $uses
     */
    private function stillUsed(Collection $ids, array $uses, string $account): Collection
    {
        $kept = collect();
        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            foreach ($uses as [$table, $column]) {
                $kept = $kept->merge(DB::table($table)->whereIn($column, $chunk->values())->distinct()->pluck($column));
            }
            $kept = $kept->merge($this->table('transactions')->where('account_type', $account)
                ->whereIn('account_id', $chunk->values())->distinct()->pluck('account_id'));
        }

        return $kept->map(fn ($id) => (int) $id)->unique()->values();
    }

    /** الحساب وما يتعلّق به: رموز دخوله وجلساته وأجهزته ومحادثات فريقه */
    private function deleteUsers(Collection $ids): int
    {
        $ids = $ids->map(fn ($id) => (int) $id)->unique()->values();
        $this->files = [...$this->files, ...$this->table('users')->whereIn('id', $ids)->pluck('avatar_path')->all()];

        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $chunk->values())->delete();
            DB::table('sessions')->whereIn('user_id', $chunk->values())->delete();
            DB::table('staff_thread_members')->whereIn('user_id', $chunk->values())->delete();
            DB::table('user_governorates')->whereIn('user_id', $chunk->values())->delete();
            DB::table('push_devices')->whereIn('user_id', $chunk->values())->delete();
        }
        foreach (['devices', 'user_grants', 'work_claims', 'shipment_follow_ups', 'announcement_reads'] as $table) {
            $this->deleteIn($table, 'user_id', $ids);
        }
        $this->nullIn('couriers', 'user_id', $ids);

        return $this->deleteIn('users', 'id', $ids);
    }

    private function count(string $key, int $n): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $n;
    }
}
