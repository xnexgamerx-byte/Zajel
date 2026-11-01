<?php

namespace App\Services\Aggregator;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CourierOutcomeRules;
use App\Actions\Shipments\ShipmentTickets;
use App\Enums\ShipmentStatus;
use App\Models\AggregatorEvent;
use App\Models\AggregatorLink;
use App\Models\Courier;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShipmentTicket;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ما يصلنا من نظام الوسيط بالـ Webhook (docs/plan/104): يُحفظ كما وصل، ثم يُطبَّق على الشحنة بقواعد
 * المندوب نفسها — كأنّ مندوب الوسيط سجّله من تطبيقه، لا أكثر:
 *
 * - «واصل» بعلامة deliver_done أو بالحالة المربوطة به. وإن سُلِّمت بغير مبلغ الوصل فلا تُسجَّل واصلةً
 *   بمبلغٍ آخر: تُفتح تذكرة «سلّمها بسعرٍ آخر» للكول سنتر، وموافقته تسجّلها (docs/plan/80).
 * - تغيّر السعر وهي مع مندوبهم (الحالة ٣ و old_price ليس -1): تذكرة «السعر تغيّر» للكول سنتر.
 * - قيد التوصيل، ولم يُسلَّم، ومؤجّل: بربط الحالات الذي تختاره الشركة، وبأسبابها.
 * - ما سواها سطر تتبّع بنصّهم يراه التاجر في مسار شحنته، أو يُسجَّل فقط.
 *
 * لا يتغيّر المبلغ ولا العنوان، ولا يُرجَع شيءٌ من هنا: الإرجاع قرار الكول سنتر كما مع كل مندوب.
 * وما لا ينطبق على حالة الشحنة الآن يصير سطر تتبّع ولا يكسر شيئاً.
 */
class AggregatorInbox
{
    /** مراحل الراجع عندهم مع الحالة ٢٣ (send_to_store) */
    private const RETURN_STAGES = [
        '0' => 'ما زالت في مخزنهم بالمحافظة',
        '1' => 'بالطريق إلى مخزنهم في بغداد',
        '2' => 'وصلت مخزنهم في بغداد',
    ];

    /** ملاحظةٌ فارغة بكلامهم */
    private const EMPTY_NOTES = ['', 'لا يوجد', 'لا یوجد', '-', 'null'];

    public function __construct(
        private readonly ChangeShipmentStatus $change,
        private readonly ShipmentTickets $tickets,
    ) {}

    /** يحفظ التحديث ويطبّقه؛ null إن وصل من قبلُ بعينه */
    public function receive(AggregatorLink $link, array $payload): ?AggregatorEvent
    {
        $remoteId = self::text($payload['id'] ?? $payload['qr_id'] ?? null);
        $statusId = self::text($payload['status_id'] ?? null);
        $deliverDone = (int) ($payload['deliver_done'] ?? 0) === 1;
        $price = self::amount($payload['price'] ?? null);
        $oldPrice = self::amount($payload['old_price'] ?? null);

        // التحديث نفسه يُعاد عند فشل الردّ: بصمته تمنع تطبيقه مرّتين
        $fingerprint = hash('sha256', implode('|', [
            $remoteId, $payload['company_order_id'] ?? '', $statusId, (int) $deliverDone, $price, $oldPrice,
            $payload['send_to_store'] ?? '', $payload['updated_at'] ?? '', $payload['issue_notes'] ?? '',
        ]));

        if (AggregatorEvent::query()->where('fingerprint', $fingerprint)->exists()) {
            return null;
        }

        $row = [
            'remote_order_id' => $remoteId,
            'company_order_id' => self::text($payload['company_order_id'] ?? null),
            'status_id' => $statusId,
            'status_text' => mb_substr((string) ($payload['status'] ?? ''), 0, 150) ?: null,
            'deliver_done' => $deliverDone,
            'price' => $price,
            'old_price' => $oldPrice,
            'fingerprint' => $fingerprint,
            'payload' => $payload,
        ];

        try {
            return DB::transaction(function () use ($link, $payload, $remoteId, $statusId, $deliverDone, $price, $oldPrice, $row) {
                $shipment = $this->match($link, $payload, $remoteId);

                [$result, $message] = $shipment === null
                    ? ['unmatched', 'لا شحنة عندنا مع الوسيط بهذا الرقم.']
                    : $this->apply($link, $shipment, $payload, $remoteId, $statusId, $deliverDone, $price, $oldPrice);

                return AggregatorEvent::create($row + [
                    'shipment_id' => $shipment?->id,
                    'result' => $result,
                    'message' => $message === null ? null : mb_substr($message, 0, 500),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return null; // وصل التحديث نفسه مرّتين في اللحظة نفسها
        } catch (Throwable $e) {
            // خطأٌ عندنا: يُحفظ التحديث ليُرى ويُعاد تطبيقه، ولا يُعاد إرساله منهم بلا فائدة
            report($e);

            return AggregatorEvent::create($row + ['result' => 'error', 'message' => 'تعذّر تطبيقه عندنا.']);
        }
    }

    /** شحنتنا مع الوسيط: برقم طلبهم إن عرفناه، وإلّا برقمنا عندهم (البادئة ورقم الوصل) */
    private function match(AggregatorLink $link, array $payload, ?string $remoteId): ?Shipment
    {
        $query = fn () => Shipment::query()->where('delivery_courier_id', $link->courier_id)->lockForUpdate();

        $shipment = $remoteId !== null ? $query()->where('aggregator_order_id', $remoteId)->first() : null;

        if ($shipment === null && ($ours = self::text($payload['company_order_id'] ?? null)) !== null) {
            $shipment = $query()->where('number', $link->localNumber($ours))->first();
        }

        // أُنشئ عندهم بغير طريقنا (يدوياً مثلاً): يُحفظ رقمه ليُعرف به بعدها
        if ($shipment !== null && $remoteId !== null && blank($shipment->aggregator_order_id)) {
            $shipment->forceFill(['aggregator_order_id' => $remoteId])->saveQuietly();
        }

        return $shipment;
    }

    /** @return array{0: string, 1: ?string} */
    private function apply(
        AggregatorLink $link,
        Shipment $shipment,
        array $payload,
        ?string $remoteId,
        ?string $statusId,
        bool $deliverDone,
        ?int $price,
        ?int $oldPrice,
    ): array {
        $courier = $link->courier;
        $action = $deliverDone ? 'delivered' : $link->actionFor($statusId);
        $note = $this->note($payload, $statusId);

        try {
            // تغيّر السعر وهي مع مندوبهم: لا يتغيّر المبلغ إلّا بموافقة الكول سنتر
            // كما في وثائقهم: الحالة «مع المندوب» (٣) والسعر القديم ليس -1
            if (($statusId === '3' || $action === 'out_for_delivery') && $oldPrice !== null && $oldPrice !== -1
                && $price !== null && $price !== (int) $shipment->cod_amount) {
                $this->toOutForDelivery($shipment, $courier, $note);

                return $this->priceTicket($shipment, $courier, $price, $note, finalize: false);
            }

            return match ($action) {
                'none' => ['recorded', null],
                'delivered' => $this->delivered($shipment, $courier, $price, $note),
                'price_change' => $this->priceChange($shipment, $courier, $price, $note),
                'out_for_delivery' => $shipment->status === ShipmentStatus::InTransit
                    ? $this->move($shipment, $courier, ShipmentStatus::OutForDelivery, $note)
                    : $this->track($shipment, $courier, $note),
                'failed_attempt' => $this->outcome($shipment, $courier, ShipmentStatus::FailedAttempt, $link->failure_reason_id, $note),
                'return_confirmed', 'return_with_courier' => $this->toReturn($link, $shipment, $courier, $note),
                'redelivery' => $this->redeliver($shipment, $courier, $note),
                'return_received' => $this->returnedToUs($link, $shipment, $courier, $note),
                'returned_to_merchant' => $this->toMerchant($link, $shipment, $courier, $note),
                // في مخزنهم لا مخزننا: «بالمخزن» عندنا لا يكون إلّا بالمسح، فلا تظهر على رفوفنا
                'at_hub' => $this->track($shipment, $courier, 'بالمخزن عند شريك التوصيل — '.$note),
                'postponed' => $this->outcome($shipment, $courier, ShipmentStatus::Postponed, $link->postpone_reason_id, $note),
                default => $this->track($shipment, $courier, $note),
            };
        } catch (ValidationException $e) {
            return ['error', collect($e->errors())->flatten()->implode(' ')];
        }
    }

    /** @return array{0: string, 1: ?string} */
    private function delivered(Shipment $shipment, Courier $courier, ?int $price, string $note): array
    {
        if ($shipment->wasDelivered()) {
            return ['recorded', 'مسلَّمة من قبل.'];
        }

        $this->toOutForDelivery($shipment, $courier, $note);

        // سلّموها وهي عندنا بعد محاولةٍ فاشلة أو راجعة: لا تُسجَّل بلا قرار، وتظهر في السجلّ ليُقرَّر فيها
        if ($shipment->status !== ShipmentStatus::OutForDelivery) {
            $this->track($shipment, $courier, $note);

            return ['error', 'سلّموها وهي «'.$shipment->status->label().'» عندنا: قرّرها من المعالجة.'];
        }

        // سلّمها بغير مبلغ الوصل: تنتظر موافقة الكول سنتر، وموافقته تسجّلها واصلة
        if ($price !== null && $price !== (int) $shipment->cod_amount) {
            return $this->priceTicket($shipment, $courier, $price, $note, finalize: true);
        }

        return $this->move($shipment, $courier, ShipmentStatus::Delivered, $note);
    }

    /**
     * حالةٌ ربطتها الشركة بـ «تغيّر السعر»: إن جاءت بسعرٍ غير سعر الوصل فتذكرةٌ للكول سنتر، وإلّا سطر تتبّع.
     * والمبلغ لا يتغيّر هنا أبداً.
     *
     * @return array{0: string, 1: ?string}
     */
    private function priceChange(Shipment $shipment, Courier $courier, ?int $price, string $note): array
    {
        $this->toOutForDelivery($shipment, $courier, $note);

        if ($price === null || $price === (int) $shipment->cod_amount) {
            return $this->track($shipment, $courier, $note.' — السعر كما هو');
        }

        return $this->priceTicket($shipment, $courier, $price, $note, finalize: false);
    }

    /**
     * راجعٌ مؤكّد عند المندوب الوسيط (راجع مؤكد، راجع عند المندوب): يمرّ بما يمرّ به أيّ طرد — قيد
     * التوصيل، ثم «لم يُسلَّم» بالسبب المختار، ثم راجع — فيبقى المال والعمولة على قواعدهما. ومكانه عند
     * الوسيط حتى نستلمه بالمسح.
     *
     * @return array{0: string, 1: ?string}
     */
    private function toReturn(AggregatorLink $link, Shipment $shipment, Courier $courier, string $note): array
    {
        if ($shipment->status === ShipmentStatus::Returning) {
            return $this->track($shipment, $courier, $note);
        }

        $this->toOutForDelivery($shipment, $courier, $note);

        if ($shipment->status === ShipmentStatus::OutForDelivery) {
            [$result, $message] = $this->outcome($shipment, $courier, ShipmentStatus::FailedAttempt, $link->failure_reason_id, $note);

            if ($result === 'error') {
                return [$result, $message];
            }
        }

        if (! in_array($shipment->status, [ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed], true)) {
            return $this->track($shipment, $courier, $note);
        }

        $this->move($shipment, $courier, ShipmentStatus::Returning, $note);

        return ['applied', 'راجع مؤكد — عند المندوب الوسيط حتى يُستلم بالمسح.'];
    }

    /**
     * يعيدون توصيلها: ما كان «لم يُسلَّم» أو «مؤجّل» عندنا يعود للوسيط «إعادة توصيل» كما يعيده قرار
     * المعالجة (ProcessFailedAttempt) — «بالطريق» إليه، ثم قيد التوصيل حين يخرجها. وغيره سطر تتبّع.
     *
     * @return array{0: string, 1: ?string}
     */
    private function redeliver(Shipment $shipment, Courier $courier, string $note): array
    {
        if (! in_array($shipment->status, [ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed], true)) {
            return $this->track($shipment, $courier, $note);
        }

        $this->move($shipment, $courier, ShipmentStatus::OutForDelivery, $note, extra: ['redelivery' => true]);

        return ['applied', 'إعادة توصيل — عادت للوسيط.'];
    }

    /**
     * يقولون سلّموها لنا راجعة. لا تُحسب في مخزننا بقولهم: تصير راجعةً إن لم تكن، وتنتظر المسح من
     * «استلام الراجع» — فهو وحده ما يثبت أنّها على رفوفنا.
     *
     * @return array{0: string, 1: ?string}
     */
    private function returnedToUs(AggregatorLink $link, Shipment $shipment, Courier $courier, string $note): array
    {
        if ($shipment->status === ShipmentStatus::Returning && $shipment->return_received_at !== null) {
            return ['recorded', 'استُلمت بالمسح من قبل.'];
        }

        // تصير راجعةً أوّلاً إن لم تكن؛ وما لا يصير راجعاً (سُلّم مثلاً) يبقى بما قاله toReturn
        if ($shipment->status !== ShipmentStatus::Returning) {
            $result = $this->toReturn($link, $shipment, $courier, $note);

            if ($shipment->status !== ShipmentStatus::Returning) {
                return $result;
            }
        }

        $this->track($shipment, $courier, 'سلّمها شريك التوصيل راجعة — '.$note);

        return ['waiting', 'يقولون سلّموها لنا راجعة: استلمها بالمسح من «استلام الراجع».'];
    }

    /**
     * «راجع عند التاجر»: لا تصل تاجرها قبل أن نستلمها بالمسح (قاعدة ChangeShipmentStatus نفسها).
     * فإن استُلمت تصير «راجع للتاجر»، وإلّا تنتظر المسح كما في returnedToUs.
     *
     * @return array{0: string, 1: ?string}
     */
    private function toMerchant(AggregatorLink $link, Shipment $shipment, Courier $courier, string $note): array
    {
        if ($shipment->status === ShipmentStatus::Returned) {
            return ['recorded', 'راجعةٌ لتاجرها من قبل.'];
        }

        if ($shipment->status === ShipmentStatus::Returning && $shipment->return_received_at !== null) {
            return $this->move($shipment, $courier, ShipmentStatus::Returned, $note);
        }

        return $this->returnedToUs($link, $shipment, $courier, $note);
    }

    /** @return array{0: string, 1: ?string} */
    private function outcome(Shipment $shipment, Courier $courier, ShipmentStatus $to, ?int $reasonId, string $note): array
    {
        if ($reasonId === null) {
            $this->track($shipment, $courier, $note);

            return ['error', 'اختر في شاشة الربط سبب «'.$to->label().'» الذي يُسجَّل لحالاتهم.'];
        }

        $this->toOutForDelivery($shipment, $courier, $note);

        if ($shipment->status !== ShipmentStatus::OutForDelivery) {
            return $this->track($shipment, $courier, $note);
        }

        $errors = CourierOutcomeRules::errors($shipment, $to, ['failure_reason_id' => $reasonId, 'note' => $note], null);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $this->move($shipment, $courier, $to, $note, $reasonId);
    }

    /** وصلتهم «بالطريق» وخرجت مع مندوبهم: تمرّ بـ «قيد التوصيل» أوّلاً كما يمرّ أيّ طرد */
    private function toOutForDelivery(Shipment $shipment, Courier $courier, string $note): void
    {
        if ($shipment->status === ShipmentStatus::InTransit) {
            $this->move($shipment, $courier, ShipmentStatus::OutForDelivery, $note);
        }
    }

    /** @return array{0: string, 1: ?string} */
    private function move(Shipment $shipment, Courier $courier, ShipmentStatus $to, string $note, ?int $reasonId = null, array $extra = []): array
    {
        $this->change->handle($shipment, $to, null, array_filter([
            'actor_type' => 'courier',
            'actor_name' => self::actor($courier),
            'courier_id' => $courier->id,
            'failure_reason_id' => $reasonId,
            'note' => $note,
        ] + $extra, fn ($v) => $v !== null));

        $shipment->refresh();

        return ['applied', $to->label()];
    }

    /** @return array{0: string, 1: ?string} */
    private function priceTicket(Shipment $shipment, Courier $courier, int $price, string $note, bool $finalize): array
    {
        $pending = ShipmentTicket::query()->where('shipment_id', $shipment->id)->open()->exists();

        if ($pending || $shipment->status !== ShipmentStatus::OutForDelivery) {
            return $this->track($shipment, $courier, $note.' — بسعر '.number_format($price));
        }

        $ticket = $this->tickets->open($shipment, $courier, null, 'price', $price,
            'من نظام الوسيط: '.$note, finalize: $finalize);

        return ['applied', ($finalize ? 'سُلّمت بسعرٍ آخر' : 'تغيّر السعر').': تذكرة '.$ticket->number.' للكول سنتر.'];
    }

    /** @return array{0: string, 1: ?string} */
    private function track(Shipment $shipment, Courier $courier, string $note): array
    {
        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'from_status' => $shipment->status->value,
            'to_status' => $shipment->status->value,
            'event_type' => 'tracking',
            'actor_type' => 'courier',
            'actor_name' => self::actor($courier),
            'courier_id' => $courier->id,
            'note' => mb_substr($note, 0, 500),
        ]);

        return ['applied', 'سطر تتبّع'];
    }

    /** نصّ حالتهم، ومرحلة الراجع، وملاحظة مندوبهم إن كتب شيئاً */
    private function note(array $payload, ?string $statusId): string
    {
        $parts = [trim((string) ($payload['status'] ?? '')) ?: 'تحديث من نظام الوسيط'];

        if ($statusId === '23' && isset(self::RETURN_STAGES[(string) ($payload['send_to_store'] ?? '')])) {
            $parts[] = self::RETURN_STAGES[(string) $payload['send_to_store']];
        }

        $issue = trim((string) ($payload['issue_notes'] ?? ''));
        if (! in_array($issue, self::EMPTY_NOTES, true)) {
            $parts[] = $issue;
        }

        return implode(' — ', $parts);
    }

    private static function actor(Courier $courier): string
    {
        return $courier->name.' — ربط آلي';
    }

    private static function text(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, 60);
    }

    private static function amount(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
