<?php

namespace App\Services\Platform;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShipmentTicket;
use App\Services\Finance\ProfitDashboard;
use App\Services\Reports\ReportPeriod;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «تقرير الشركة» في لوحة النواة (docs/plan/111): لصاحب المنصّة وحده، يقرأ نظام شركةٍ في مدّة:
 * شحناتها وربحها، وتقويمها يوماً بيوم، وما حُذف من شحناتها وما تغيّر سعره ومن فعله.
 *
 * يقرأ فقط، داخل الشركة (Tenancy::runFor)، بالأرقام التي تراها الشركة في نظامها: الربح من دفترها
 * (ProfitDashboard)، والحذف من سجلّ تدقيقها، وتغيّر السعر من أحداث شحناتها وتذاكرها.
 */
final class CompanyReport
{
    /** أكثر ما يُعرض من كلّ قائمة: التقرير نظرة، والتفاصيل في نظام الشركة */
    public const LIST_LIMIT = 200;

    /** حقول السعر في التعديل: المبلغ وأجورها */
    private const PRICE_FIELDS = [
        'cod_amount' => 'المبلغ', 'delivery_fee' => 'أجرة التوصيل', 'return_fee' => 'أجرة الراجع',
        'collected_amount' => 'المحصَّل', 'merchant_due' => 'مستحقّ التاجر',
    ];

    public function __construct(
        private readonly Company $company,
        private readonly ReportPeriod $period,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        return Tenancy::runFor($this->company, fn () => [
            'shipments' => $this->shipments(),
            'money' => $this->money(),
            'calendar' => $this->calendar(),
            'deleted' => $this->deleted(),
            'prices' => $this->priceChanges(),
        ]);
    }

    /** @return array<string, int> */
    private function shipments(): array
    {
        [$from, $to] = $this->period->bounds();
        $in = fn (string $column) => Shipment::query()->whereBetween($column, [$from, $to]);

        return [
            // أُنشئت في المدّة، ومنها ما حُذف بعدها: يُعدّ هنا ويُعدّ في «حُذفت»
            'created' => Shipment::withTrashed()->whereBetween('created_at', [$from, $to])->count(),
            'delivered' => $in('delivered_at')->count(),
            'returned' => $in('returned_at')->count(),
            'deleted' => Shipment::onlyTrashed()->whereBetween('deleted_at', [$from, $to])->count(),
            'cod' => (int) $in('delivered_at')->sum('collected_amount'),
        ];
    }

    /** @return array{ready: bool, fees?: int, costs?: int, total?: int} */
    private function money(): array
    {
        $board = new ProfitDashboard;

        if (! $board->initialized()) {
            return ['ready' => false];
        }

        $net = $board->netProfit($this->period);

        return ['ready' => true, 'fees' => (int) $net['fees'], 'costs' => (int) $net['costs'], 'total' => (int) $net['total']];
    }

    /**
     * يوماً بيوم: ما أُنشئ وما سُلّم. لمدّةٍ تزيد على ٩٢ يوماً لا تقويم: يطول ولا يُقرأ.
     *
     * @return list<array{date: Carbon, created: int, delivered: int}>|null
     */
    private function calendar(): ?array
    {
        if ($this->period->days() > 92) {
            return null;
        }

        [$from, $to] = $this->period->bounds();
        $count = fn (string $column) => Shipment::withTrashed()->whereBetween($column, [$from, $to])
            ->selectRaw("date($column) as day, count(*) as n")->groupByRaw("date($column)")->toBase()->pluck('n', 'day');
        $created = $count('created_at');
        $delivered = $count('delivered_at');

        $days = [];
        for ($day = $this->period->from->copy()->startOfDay(); $day->lte($this->period->to); $day->addDay()) {
            $key = $day->toDateString();
            $days[] = ['date' => $day->copy(), 'created' => (int) ($created[$key] ?? 0), 'delivered' => (int) ($delivered[$key] ?? 0)];
        }

        return $days;
    }

    /** @return Collection<int, array<string, mixed>> المحذوفة في المدّة: رقمها وتاجرها ومبلغها، ومن حذفها ولماذا */
    private function deleted(): Collection
    {
        [$from, $to] = $this->period->bounds();

        $shipments = Shipment::onlyTrashed()->whereBetween('deleted_at', [$from, $to])
            ->with('merchant:id,business_name')->latest('deleted_at')->limit(self::LIST_LIMIT)->get();

        $logs = AuditLog::query()->where('action', 'shipment_deleted')->where('auditable_type', Shipment::class)
            ->whereIn('auditable_id', $shipments->pluck('id'))->latest('id')->get()->keyBy('auditable_id');

        return $shipments->map(fn (Shipment $s) => [
            'number' => $s->number,
            'merchant' => $s->merchant?->business_name,
            'amount' => (int) $s->cod_amount,
            'status' => $s->status->label(),
            'at' => $s->deleted_at,
            'by' => $logs[$s->id]->user_name ?? null,
            'reason' => $logs[$s->id]->new_values['reason'] ?? null,
        ]);
    }

    /**
     * ما تغيّر سعره في المدّة: تعديلٌ للمبلغ أو أجوره (بيد موظّف، أو تصحيحٌ إجباريّ)، وتصحيحُ مبلغٍ بعد التسليم،
     * وتذكرة «السعر تغيّر» اعتمدها الكول سنتر.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function priceChanges(): Collection
    {
        [$from, $to] = $this->period->bounds();
        $rows = collect();

        ShipmentEvent::query()->whereIn('event_type', ['edited', 'amount_confirmed'])->whereBetween('created_at', [$from, $to])
            ->with(['shipment' => fn ($q) => $q->withTrashed()->select('id', 'number', 'merchant_id'), 'shipment.merchant:id,business_name'])
            ->latest('id')->limit(self::LIST_LIMIT * 5)->get()
            ->each(function (ShipmentEvent $e) use ($rows) {
                if ($e->event_type === 'amount_confirmed') {
                    if (! str_starts_with((string) $e->note, 'تصحيح المبلغ')) {
                        return;
                    }
                    $rows->push($this->row($e->shipment, $e->created_at, $e->actor_name, 'تصحيح المبلغ بعد التسليم', (string) $e->note));

                    return;
                }

                $changes = array_intersect_key((array) (($e->meta ?? [])['changes'] ?? []), self::PRICE_FIELDS);
                if ($changes === []) {
                    return;
                }

                $what = collect($changes)->map(fn ($c, $field) => self::PRICE_FIELDS[$field].': '
                    .number_format((int) ($c['from'] ?? 0)).' ← '.number_format((int) ($c['to'] ?? 0)))->implode(' · ');
                $rows->push($this->row($e->shipment, $e->created_at, $e->actor_name,
                    ($e->meta['override'] ?? false) ? 'تصحيحٌ إجباريّ' : 'تعديل الشحنة', $what));
            });

        ShipmentTicket::query()->where('kind', 'price')->where('status', 'approved')->whereBetween('handled_at', [$from, $to])
            ->with(['shipment' => fn ($q) => $q->withTrashed()->select('id', 'number', 'merchant_id'), 'shipment.merchant:id,business_name', 'handledBy:id,name'])
            ->latest('handled_at')->limit(self::LIST_LIMIT)->get()
            ->each(fn (ShipmentTicket $t) => $rows->push($this->row($t->shipment, $t->handled_at, $t->handledBy?->name,
                'تذكرة «السعر تغيّر» '.$t->number, 'المبلغ: '.number_format($t->current_amount).' ← '.number_format((int) ($t->approved_amount ?? $t->requested_amount)))));

        return $rows->sortByDesc(fn ($r) => $r['at']?->getTimestamp() ?? 0)->take(self::LIST_LIMIT)->values();
    }

    /** @return array<string, mixed> */
    private function row(?Shipment $shipment, ?Carbon $at, ?string $by, string $how, string $what): array
    {
        return [
            'number' => $shipment?->number,
            'merchant' => $shipment?->merchant?->business_name,
            'at' => $at,
            'by' => $by,
            'how' => $how,
            'what' => $what,
        ];
    }
}
