<?php

namespace App\Actions\Transport;

use App\Enums\ShipmentStatus;
use App\Models\Bag;
use App\Models\Hub;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\SequenceGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * التكييس.
 *
 * نقل ٣٠٠ شحنة من بغداد إلى البصرة يعني مسح ٣٠٠ باركود مرّتين — أو
 * مسح كيس واحد مرّتين. لكنّ الكيس لا يُغيّر حالة ما فيه: الطرد يبقى
 * «في المخزن» حتى تتحرّك السيارة فعلاً، وإلّا صارت الشحنة «قيد النقل»
 * وهي على رفّ المخزن منذ يومين.
 */
class BagShipments
{
    public function __construct(protected SequenceGenerator $sequences) {}

    public function create(Hub $from, Hub $to, ?User $actor = null, ?string $notes = null): Bag
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'to_hub_id' => 'الكيس ينتقل بين مركزين مختلفين.',
            ]);
        }

        return Bag::create([
            'code'        => $this->sequences->next('bag'),
            'from_hub_id' => $from->id,
            'to_hub_id'   => $to->id,
            'status'      => 'open',
            'notes'       => $notes,
        ]);
    }

    /**
     * إضافة شحنات بأرقام وصولها — الماسح الضوئي يكتب الرقم ويُرسل.
     *
     * @return array{added: Collection<int, Shipment>, errors: array<string, string>}
     */
    public function add(Bag $bag, array $numbers, ?User $actor = null): array
    {
        $this->assertOpen($bag);

        $numbers = collect($numbers)->map(fn ($n) => trim((string) $n))->filter()->unique();

        if ($numbers->isEmpty()) {
            return ['added' => collect(), 'errors' => []];
        }

        return DB::transaction(function () use ($bag, $numbers, $actor) {
            $found = Shipment::query()
                ->where(fn ($q) => $q->whereIn('number', $numbers)->orWhereIn('barcode', $numbers))
                ->lockForUpdate()
                ->get();

            $added = collect();
            $errors = [];

            foreach ($numbers as $number) {
                $shipment = $found->first(fn (Shipment $s) => $s->number === $number || $s->barcode === $number);

                if (! $shipment) {
                    $errors[$number] = 'لا وصل بهذا الرقم.';

                    continue;
                }

                if ($error = $this->rejectionReason($bag, $shipment)) {
                    $errors[$number] = $error;

                    continue;
                }

                $bag->shipments()->attach($shipment->id, [
                    'company_id'       => $bag->company_id,
                    'added_at'         => now(),
                    'added_by_user_id' => $actor?->id,
                ]);

                $shipment->forceFill(['current_bag_id' => $bag->id])->save();

                $this->log($shipment, 'bagged', "أُضيفت إلى الكيس {$bag->code}", $actor);

                $added->push($shipment);
            }

            $this->recount($bag);

            return ['added' => $added, 'errors' => $errors];
        });
    }

    public function remove(Bag $bag, Shipment $shipment, ?User $actor = null): void
    {
        $this->assertOpen($bag);

        DB::transaction(function () use ($bag, $shipment, $actor) {
            $bag->shipments()->detach($shipment->id);

            if ((int) $shipment->current_bag_id === (int) $bag->id) {
                $shipment->forceFill(['current_bag_id' => null])->save();
            }

            $this->log($shipment, 'unbagged', "أُخرجت من الكيس {$bag->code}", $actor);
            $this->recount($bag);
        });
    }

    /** الختم يُقفل المحتوى: بعده لا يُضاف ولا يُخرَج إلّا بفتح الكيس. */
    public function seal(Bag $bag, ?User $actor = null): Bag
    {
        $this->assertOpen($bag);

        if ($bag->shipments()->wherePivotNull('removed_at')->count() === 0) {
            throw ValidationException::withMessages(['bag' => 'لا تُختم كيساً فارغاً.']);
        }

        $bag->forceFill([
            'status'            => 'sealed',
            'sealed_at'         => now(),
            'sealed_by_user_id' => $actor?->id,
        ])->save();

        return $bag->refresh();
    }

    /**
     * فتح الكيس في مركز الوصول: هنا تصير الشحنات «في المخزن» عند المركز
     * الجديد، فالمسؤولية انتقلت وصار الطرد جاهزاً للتوزيع من هنا.
     */
    public function open(Bag $bag, ?User $actor = null): Bag
    {
        if ($bag->status !== 'received') {
            throw ValidationException::withMessages([
                'bag' => "الكيس {$bag->code} لم يُستلم بعد في مركز الوصول.",
            ]);
        }

        return DB::transaction(function () use ($bag, $actor) {
            $change = app(\App\Actions\Shipments\ChangeShipmentStatus::class);

            foreach ($bag->shipments()->wherePivotNull('removed_at')->get() as $shipment) {
                $shipment->forceFill(['current_bag_id' => null])->save();

                if ($shipment->status->canMoveTo(ShipmentStatus::AtHub)) {
                    $change->handle($shipment->refresh(), ShipmentStatus::AtHub, $actor, [
                        'hub_id' => $bag->to_hub_id,
                        'note'   => "فُتح الكيس {$bag->code} في مركز الوصول",
                    ]);
                }
            }

            $bag->forceFill(['status' => 'opened', 'opened_at' => now()])->save();

            return $bag->refresh();
        });
    }

    /** لماذا لا تدخل هذه الشحنة هذا الكيس. */
    protected function rejectionReason(Bag $bag, Shipment $shipment): ?string
    {
        if ($shipment->current_bag_id && (int) $shipment->current_bag_id !== (int) $bag->id) {
            $other = Bag::find($shipment->current_bag_id);

            return "في الكيس {$other?->code} سلفاً.";
        }

        if ($shipment->current_bag_id && (int) $shipment->current_bag_id === (int) $bag->id) {
            return 'في هذا الكيس سلفاً.';
        }

        if (in_array($shipment->status, ShipmentStatus::terminal(), true)) {
            return "حالتها «{$shipment->status->label()}» فلا تُنقَل.";
        }

        return null;
    }

    protected function assertOpen(Bag $bag): void
    {
        if (! $bag->isOpen()) {
            throw ValidationException::withMessages([
                'bag' => "الكيس {$bag->code} مختوم. افتحه أو أنشئ كيساً جديداً.",
            ]);
        }
    }

    protected function recount(Bag $bag): void
    {
        $bag->forceFill([
            'shipments_count' => $bag->shipments()->wherePivotNull('removed_at')->count(),
        ])->save();
    }

    protected function log(Shipment $shipment, string $type, string $note, ?User $actor): void
    {
        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'from_status' => $shipment->status->value,
            'to_status'   => $shipment->status->value,
            'event_type'  => $type,
            'actor_type'  => $actor ? 'user' : 'system',
            'actor_id'    => $actor?->id,
            'actor_name'  => $actor?->name,
            'hub_id'      => $shipment->hub_id,
            'note'        => $note,
            'ip'          => request()->ip(),
        ]);
    }
}
