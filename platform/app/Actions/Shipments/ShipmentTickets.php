<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Models\Courier;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShipmentTicket;
use App\Models\User;
use App\Services\SequenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «طلب تغيير المبلغ» من أوّله إلى آخره (docs/plan/30): المندوب يفتحه عند الباب،
 * والكول سنتر المختصّة بمحافظة الشحنة تعتمده أو ترفضه، ويُغلق وحده إن خرجت
 * الشحنة من يد المندوب قبل ذلك. وكل خطوةٍ في سجلّ الشحنة.
 */
class ShipmentTickets
{
    public const MAX_AMOUNT = 100_000_000;

    public function __construct(
        protected SequenceGenerator $sequences,
        protected UpdateShipment $update,
    ) {}

    public function open(Shipment $shipment, Courier $courier, User $actor, string $kind, int $amount, string $reason): ShipmentTicket
    {
        return DB::transaction(function () use ($shipment, $courier, $actor, $kind, $amount, $reason) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            if ($shipment->status !== ShipmentStatus::OutForDelivery || (int) $shipment->delivery_courier_id !== $courier->id) {
                throw ValidationException::withMessages(['requested_amount' => 'هذه الشحنة لم تعد بيدك.']);
            }

            $pending = ShipmentTicket::query()->where('shipment_id', $shipment->id)
                ->where(fn ($q) => $q->open()->orWhere(fn ($w) => $w->awaitingPartial()))
                ->first();

            if ($pending) {
                throw ValidationException::withMessages(['requested_amount' => $pending->isOpen()
                    ? "طلبك {$pending->number} بانتظار الكول سنتر — انتظر جوابه."
                    : "اعتُمد لك واصلٌ جزئي بـ".number_format($pending->approved_amount).' — سلّم به.']);
            }

            $this->checkAmount($shipment, $kind, $amount, 'requested_amount');

            $ticket = ShipmentTicket::create([
                'number'            => $this->sequences->next('shipment_ticket'),
                'shipment_id'       => $shipment->id,
                'governorate_id'    => $shipment->governorate_id,
                'courier_id'        => $courier->id,
                'opened_by_user_id' => $actor->id,
                'kind'              => $kind,
                'current_amount'    => (int) $shipment->cod_amount,
                'requested_amount'  => $amount,
                'reason'            => $reason,
            ]);

            $this->log($shipment, $actor, 'courier', "طلب المندوب تغيير المبلغ ({$ticket->number}): "
                .number_format($ticket->current_amount).' ← '.number_format($amount)
                .' — '.$ticket->kindLabel().'. '.$reason, $courier->id);

            return $ticket;
        });
    }

    public function approve(ShipmentTicket $ticket, int $amount, ?string $reply, User $actor): ShipmentTicket
    {
        return DB::transaction(function () use ($ticket, $amount, $reply, $actor) {
            [$ticket, $shipment] = $this->lockOpen($ticket);

            $this->checkAmount($shipment, $ticket->kind, $amount, 'approved_amount');

            $done = ['status' => 'approved', 'approved_amount' => $amount, 'reply' => $reply,
                'handled_by_user_id' => $actor->id, 'handled_at' => now()];

            if ($ticket->kind === 'price') {
                // يُعدَّل مبلغ الشحنة الآن، ويسلّم المندوب «واصل» بالمبلغ الجديد
                $this->update->handle($shipment, UpdateShipment::withAmount($shipment, $amount), $actor,
                    "تغيير المبلغ بطلب المندوب ({$ticket->number})".(filled($reply) ? ' — '.$reply : ''));
                $done['used_at'] = now();
            } else {
                $this->log($shipment, $actor, 'user', "اعتُمد الواصل الجزئي ({$ticket->number}) بـ"
                    .number_format($amount).(filled($reply) ? ' — '.$reply : ''), $ticket->courier_id);
            }

            $ticket->update($done);

            return $ticket;
        });
    }

    public function reject(ShipmentTicket $ticket, string $reply, User $actor): ShipmentTicket
    {
        return DB::transaction(function () use ($ticket, $reply, $actor) {
            [$ticket, $shipment] = $this->lockOpen($ticket, withCourier: false);

            $ticket->update(['status' => 'rejected', 'reply' => $reply,
                'handled_by_user_id' => $actor->id, 'handled_at' => now()]);

            $this->log($shipment, $actor, 'user', "رُفض طلب تغيير المبلغ ({$ticket->number}) — {$reply}", $ticket->courier_id);

            return $ticket;
        });
    }

    /**
     * الشحنة خرجت من يد المندوب (ChangeShipmentStatus): ما لم يُعالَج من طلباته يُغلق،
     * والواصل الجزئي المعتمد يُعلَّم مستعمَلاً إن سُلِّمت به، وإلّا سقط — لا يبقى
     * لغدٍ تخرج فيه الشحنة ثانيةً.
     */
    public static function settle(Shipment $shipment, ShipmentStatus $to, ?int $usedTicketId = null): void
    {
        // سُلِّمت بمبلغها كما هو — والاستبدال «واصل جزئي» بمبلغه كاملاً (الوثيقة ٣١)
        $asWritten = $shipment->wasDelivered() && (int) $shipment->collected_amount === (int) $shipment->cod_amount;

        ShipmentTicket::query()->where('shipment_id', $shipment->id)
            ->where(fn ($q) => $q->open()->orWhere(fn ($w) => $w->awaitingPartial()))
            ->get()
            ->each(function (ShipmentTicket $ticket) use ($to, $usedTicketId, $asWritten) {
                if ($ticket->id === $usedTicketId) {
                    $ticket->update(['used_at' => now()]);

                    return;
                }

                $ticket->update(['status' => 'closed', 'closed_note' => match (true) {
                    $ticket->isOpen() && $asWritten => 'سُلِّمت بالمبلغ الأصلي قبل الجواب.',
                    $ticket->isOpen() => "صارت الشحنة «{$to->label()}» قبل الجواب.",
                    default           => "لم يُسلَّم به: صارت الشحنة «{$to->label()}».",
                }]);
            });
    }

    /**
     * المبلغ الجديد: غير الحاليّ، والجزئيّ أقلّ منه — يأخذ الزبون بعض الطلب ويدفع بعضه.
     */
    protected function checkAmount(Shipment $shipment, string $kind, int $amount, string $field): void
    {
        $cod = (int) $shipment->cod_amount;

        $error = match (true) {
            ! array_key_exists($kind, ShipmentTicket::KINDS) => 'اختر سبب التغيير.',
            $amount < 0 || $amount > self::MAX_AMOUNT       => 'المبلغ غير صحيح.',
            $amount === $cod                                => 'هذا المبلغ نفسه — لا تغيير فيه.',
            $kind === 'partial' && $amount > $cod            => 'في الواصل الجزئي يدفع الزبون أقلّ من المبلغ الأصلي ('.number_format($cod).').',
            default                                          => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages([$field => $error]);
        }
    }

    /**
     * التذكرة والشحنة مقفولتين، والتذكرة ما زالت مفتوحة. والاعتماد يشترط أن تكون
     * الشحنة مع مندوبها بعد؛ والرفض لا — يُرفض الطلب ولو تغيّر حالها.
     *
     * @return array{0: ShipmentTicket, 1: Shipment}
     */
    protected function lockOpen(ShipmentTicket $ticket, bool $withCourier = true): array
    {
        $ticket = ShipmentTicket::query()->lockForUpdate()->findOrFail($ticket->id);

        if (! $ticket->isOpen()) {
            throw ValidationException::withMessages([
                'ticket' => "الطلب {$ticket->number} عولج من قبل: {$ticket->statusLabel()}"
                    .($ticket->closed_note ? ' — '.$ticket->closed_note : '.'),
            ]);
        }

        $shipment = Shipment::query()->lockForUpdate()->findOrFail($ticket->shipment_id);

        if ($withCourier && ($shipment->status !== ShipmentStatus::OutForDelivery
                || (int) $shipment->delivery_courier_id !== (int) $ticket->courier_id)) {
            throw ValidationException::withMessages([
                'ticket' => "الشحنة {$shipment->number} لم تعد مع هذا المندوب («{$shipment->status->label()}») — ارفض الطلب بدل اعتماده.",
            ]);
        }

        return [$ticket, $shipment];
    }

    protected function log(Shipment $shipment, User $actor, string $actorType, string $note, ?int $courierId): void
    {
        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'from_status' => $shipment->status->value,
            'to_status'   => $shipment->status->value,
            'event_type'  => 'ticket',
            'actor_type'  => $actorType,
            'actor_id'    => $actor->id,
            'actor_name'  => $actor->name,
            'courier_id'  => $courierId,
            'note'        => $note,
            'ip'          => request()->ip(),
        ]);
    }
}
