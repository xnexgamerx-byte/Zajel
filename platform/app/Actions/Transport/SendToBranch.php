<?php

namespace App\Actions\Transport;

use App\Enums\ShipmentStatus;
use App\Models\Hub;
use App\Models\Manifest;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «أرسل إلى فرع» بخطوةٍ واحدة (docs/plan/38).
 *
 * كان النقل بين الفروع سبع خطوات في ثلاث شاشات: كيسٌ يُفتح، وتُمسح فيه الشحنات، ويُختم،
 * ثم كشفٌ يُنشأ، ويُحمَّل عليه الكيس، ويُرسَل — ثم في الفرع الآخر استلام الكشف وفتح كل كيس.
 * هنا تُختار الشحنات (أو تُمسح) ويُختار من يحملها، فتُبنى الخطوات كلّها معاً في معاملةٍ
 * واحدة: الكيس والكشف موجودان كما كانا — بأثرهما في السجلّ والأرشيف — بلا أن يُطلب
 * من الموظّف بناؤهما بيده.
 *
 * ويُرسَل ما على رفّنا وحده: شحنةٌ بالمخزن تنتظر طريقها، أو راجعٌ استُلم من مندوبه
 * وتاجره في الفرع الآخر. والراجع يسافر راجعاً (BagShipments::open) فيصل فرع تاجره
 * جاهزاً للتسليم، لا شحنةً جديدة.
 */
class SendToBranch
{
    public function __construct(
        protected BagShipments $bags,
        protected RunManifest $manifests,
    ) {}

    /**
     * ما يُرسَل من مركزٍ: بالمخزن خارج الأكياس، أو راجعٌ على الرفّ.
     */
    public static function sendable(Builder $q, Hub $from): Builder
    {
        return $q->whereNull('shipments.current_bag_id')
            ->where(fn (Builder $w) => $w->where('shipments.hub_id', $from->id)->orWhereNull('shipments.hub_id'))
            ->where(fn (Builder $w) => $w->where('shipments.status', ShipmentStatus::AtHub->value)
                ->orWhere(fn (Builder $r) => $r->where('shipments.status', ShipmentStatus::Returning->value)
                    ->whereNotNull('shipments.return_received_at')));
    }

    /**
     * @param  list<int>  $shipmentIds
     * @param  array{courier_id?: ?int, driver_name?: ?string, driver_phone?: ?string, vehicle_number?: ?string, notes?: ?string}  $carrier
     * @return array{manifest: Manifest, sent: int, returns: int, skipped: array<string, string>}
     */
    public function handle(Hub $from, Hub $to, array $shipmentIds, array $carrier, User $actor): array
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to' => 'الفرع المرسَل إليه هو فرعك نفسه.']);
        }

        return DB::transaction(function () use ($from, $to, $shipmentIds, $carrier, $actor) {
            $chosen = Shipment::query()->visibleTo($actor)->whereIn('shipments.id', $shipmentIds)->lockForUpdate()->get();
            $ready = static::sendable(Shipment::query()->whereIn('shipments.id', $chosen->pluck('id')->all() ?: [0]), $from)
                ->pluck('shipments.id')->all();

            $skipped = [];

            foreach ($chosen as $shipment) {
                if (! in_array($shipment->id, $ready, true)) {
                    $skipped[$shipment->number] = $shipment->current_bag_id
                        ? 'في كيسٍ سلفاً'
                        : ($shipment->status === ShipmentStatus::Returning && ! $shipment->return_received_at
                            ? 'راجعٌ ما زال بيد المندوب: يُستلم منه أوّلاً'
                            : "حالتها «{$shipment->statusLabel()}» أو ليست في مركزك");
                }
            }

            $sending = $chosen->whereIn('id', $ready);

            if ($sending->isEmpty()) {
                throw ValidationException::withMessages([
                    'shipment_ids' => 'لا شحنة بين المختارة تُرسَل من مركزك'.($skipped ? ': '.collect($skipped)->take(5)
                        ->map(fn ($why, $number) => "{$number} ({$why})")->implode('، ') : '.'),
                ]);
            }

            // الشحنة بلا مركز مسجَّل على رفّ من يُرسلها: الكيس يخرج من مكانٍ واحد
            Shipment::whereIn('id', $sending->whereNull('hub_id')->pluck('id')->all() ?: [0])->update(['hub_id' => $from->id]);

            $bag = $this->bags->create($from, $to, $actor, 'نقل إلى '.$to->name);
            $result = $this->bags->add($bag, $sending->pluck('number')->all(), $actor);
            $skipped += $result['errors'];

            if ($result['added']->isEmpty()) {
                throw ValidationException::withMessages(['shipment_ids' => 'لم تُضَف أيّ شحنة إلى الإرسال.']);
            }

            $this->bags->seal($bag, $actor);

            $manifest = $this->manifests->create($from, $to, $carrier, $actor);
            $this->manifests->load($manifest, $bag->refresh(), $actor);
            $manifest = $this->manifests->dispatch($manifest->refresh(), $actor);

            return [
                'manifest' => $manifest,
                'sent'     => $result['added']->count(),
                'returns'  => $result['added']->filter(fn (Shipment $s) => $s->status === ShipmentStatus::Returning)->count(),
                'skipped'  => $skipped,
            ];
        });
    }
}
