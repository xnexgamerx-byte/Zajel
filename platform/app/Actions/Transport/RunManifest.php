<?php

namespace App\Actions\Transport;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Bag;
use App\Models\Hub;
use App\Models\Manifest;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\SequenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * المنفيست: سيارة واحدة، سائق واحد، عدّة أكياس.
 *
 * قيمته كلّها تظهر يوم يختفي كيس: الكشف هو ما يُثبت مَن سلّمه ومَن
 * استلمه ومتى. ولذلك لا يُقفَل منفيست وصلت أكياسه ناقصةً بصمت — الكيس
 * المفقود يُوسَم، وتُوسَم كل شحنة فيه، فلا يبقى الطرد «قيد النقل» إلى
 * الأبد بينما لا أحد يبحث عنه.
 */
class RunManifest
{
    public function __construct(
        protected SequenceGenerator $sequences,
        protected ChangeShipmentStatus $changeStatus,
    ) {}

    public function create(Hub $from, Hub $to, array $data = [], ?User $actor = null): Manifest
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_hub_id' => 'المنفيست ينتقل بين مركزين مختلفين.']);
        }

        return Manifest::create([
            'code'           => $this->sequences->next('manifest'),
            'from_hub_id'    => $from->id,
            'to_hub_id'      => $to->id,
            'status'         => 'draft',
            'driver_name'    => $data['driver_name'] ?? null,
            'driver_phone'   => $data['driver_phone'] ?? null,
            'vehicle_number' => $data['vehicle_number'] ?? null,
            'notes'          => $data['notes'] ?? null,
        ]);
    }

    /** تحميل كيس مختوم على الكشف. */
    public function load(Manifest $manifest, Bag $bag, ?User $actor = null): void
    {
        $this->assertDraft($manifest);

        if ($bag->status !== 'sealed') {
            throw ValidationException::withMessages([
                'bag_id' => "الكيس {$bag->code} غير مختوم. الختم يسبق التحميل.",
            ]);
        }

        // كيس إلى وجهة أخرى على سيارة هذه الوجهة: هكذا تضيع الأكياس
        if ((int) $bag->from_hub_id !== (int) $manifest->from_hub_id
            || (int) $bag->to_hub_id !== (int) $manifest->to_hub_id) {
            throw ValidationException::withMessages([
                'bag_id' => "مسار الكيس {$bag->code} يخالف مسار الكشف.",
            ]);
        }

        if ($manifest->bags()->whereKey($bag->id)->exists()) {
            throw ValidationException::withMessages(['bag_id' => 'الكيس محمَّل سلفاً على هذا الكشف.']);
        }

        DB::transaction(function () use ($manifest, $bag) {
            $manifest->bags()->attach($bag->id, [
                'company_id' => $manifest->company_id,
                'loaded_at'  => now(),
            ]);

            $this->recount($manifest);
        });
    }

    public function unload(Manifest $manifest, Bag $bag): void
    {
        $this->assertDraft($manifest);

        DB::transaction(function () use ($manifest, $bag) {
            $manifest->bags()->detach($bag->id);
            $this->recount($manifest);
        });
    }

    /** انطلقت السيارة: الآن فقط تصير الشحنات «قيد النقل». */
    public function dispatch(Manifest $manifest, ?User $actor = null): Manifest
    {
        $this->assertDraft($manifest);

        if ($manifest->bags()->count() === 0) {
            throw ValidationException::withMessages(['manifest' => 'لا يُرسَل كشف بلا أكياس.']);
        }

        return DB::transaction(function () use ($manifest, $actor) {
            foreach ($manifest->bags as $bag) {
                $bag->forceFill(['status' => 'in_transit'])->save();

                foreach ($this->bagShipments($bag) as $shipment) {
                    if ($shipment->status->canMoveTo(ShipmentStatus::InTransit)) {
                        $this->changeStatus->handle($shipment, ShipmentStatus::InTransit, $actor, [
                            'note' => "غادرت مع الكشف {$manifest->code}",
                        ]);
                    }
                }
            }

            $manifest->forceFill([
                'status'                 => 'dispatched',
                'departed_at'            => now(),
                'dispatched_by_user_id'  => $actor?->id,
            ])->save();

            return $manifest->refresh();
        });
    }

    /**
     * استلام الكشف الوارد.
     *
     * ما لم يُذكر في المستلَم فهو مفقود، لا منسيّ: يُوسَم الكيس ويُسجَّل
     * على كل شحنة فيه أنها لم تصل، فيبقى لها أثر يُبحث فيه.
     *
     * @param  array<int>  $receivedBagIds
     */
    public function receive(Manifest $manifest, array $receivedBagIds, ?User $actor = null, ?string $notes = null): Manifest
    {
        if ($manifest->status !== 'dispatched') {
            throw ValidationException::withMessages([
                'manifest' => "الكشف {$manifest->code} ليس في الطريق.",
            ]);
        }

        return DB::transaction(function () use ($manifest, $receivedBagIds, $actor, $notes) {
            $received = collect($receivedBagIds)->map(fn ($id) => (int) $id);

            foreach ($manifest->bags as $bag) {
                $arrived = $received->contains((int) $bag->id);

                $manifest->bags()->updateExistingPivot($bag->id, [
                    'unloaded_at' => $arrived ? now() : null,
                    'is_missing'  => ! $arrived,
                ]);

                if ($arrived) {
                    $bag->forceFill([
                        'status'               => 'received',
                        'received_at'          => now(),
                        'received_by_user_id'  => $actor?->id,
                    ])->save();

                    continue;
                }

                foreach ($this->bagShipments($bag) as $shipment) {
                    $this->flagMissing($shipment, $bag, $manifest, $actor);
                }
            }

            $manifest->forceFill([
                'status'                => 'arrived',
                'arrived_at'            => now(),
                'received_by_user_id'   => $actor?->id,
                'notes'                 => $notes ?? $manifest->notes,
            ])->save();

            return $manifest->refresh();
        });
    }

    /** @return \Illuminate\Support\Collection<int, Shipment> */
    protected function bagShipments(Bag $bag)
    {
        return $bag->shipments()->wherePivotNull('removed_at')->get();
    }

    protected function flagMissing(Shipment $shipment, Bag $bag, Manifest $manifest, ?User $actor): void
    {
        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'from_status' => $shipment->status->value,
            'to_status'   => $shipment->status->value,
            'event_type'  => 'bag_missing',
            'actor_type'  => $actor ? 'user' : 'system',
            'actor_id'    => $actor?->id,
            'actor_name'  => $actor?->name,
            'note'        => "لم يصل الكيس {$bag->code} مع الكشف {$manifest->code} — الشحنة مفقودة في الطريق",
            'ip'          => request()->ip(),
        ]);
    }

    protected function assertDraft(Manifest $manifest): void
    {
        if ($manifest->status !== 'draft') {
            throw ValidationException::withMessages([
                'manifest' => "الكشف {$manifest->code} غادر سلفاً ولا يُعدَّل.",
            ]);
        }
    }

    protected function recount(Manifest $manifest): void
    {
        $bags = $manifest->bags()->get();

        $manifest->forceFill([
            'bags_count'      => $bags->count(),
            'shipments_count' => (int) $bags->sum('shipments_count'),
        ])->save();
    }
}
