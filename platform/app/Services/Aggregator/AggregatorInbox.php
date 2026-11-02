<?php

namespace App\Services\Aggregator;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CourierOutcomeRules;
use App\Actions\Shipments\ShipmentTickets;
use App\Enums\ShipmentStatus;
use App\Models\AggregatorEvent;
use App\Models\AggregatorLink;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShipmentTicket;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
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

    /** يحفظ التحديث ويطبّقه في الحال؛ null إن وصل من قبلُ بعينه */
    public function receive(AggregatorLink $link, array $payload): ?AggregatorEvent
    {
        $event = $this->accept($link, $payload);

        if ($event !== null) {
            $this->process($link, $event);
        }

        return $event?->refresh();
    }

    /**
     * يحفظ التحديث كما وصل «بانتظار التطبيق» ولا يطبّقه (docs/plan/106): الردّ عليهم لا ينتظر تغيير الحالة
     * ولا إشعارات الهواتف، فلا تتجاوز مهلتهم (٣ ثوانٍ) فيعيدوه بعد دقائق. null إن وصل من قبلُ بعينه.
     */
    public function accept(AggregatorLink $link, array $payload): ?AggregatorEvent
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

        try {
            return AggregatorEvent::create([
                'remote_order_id' => $remoteId,
                'company_order_id' => self::text($payload['company_order_id'] ?? null),
                'status_id' => $statusId,
                'status_text' => mb_substr((string) ($payload['status'] ?? ''), 0, 150) ?: null,
                'deliver_done' => $deliverDone,
                'price' => $price,
                'old_price' => $oldPrice,
                'fingerprint' => $fingerprint,
                'payload' => $payload,
                'result' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // وصل التحديث نفسه مرّتين في اللحظة نفسها
        }
    }

    /**
     * يطبّق ما ينتظر من تحديثات الشركة بترتيب وصولها، واحداً بعد واحد: بعد الردّ عليهم، ومن المجدوِل كل دقيقة
     * لما لم يُطبَّق (انقطع الخادم بعد الردّ مثلاً). قفلٌ للشركة يمنع أن يُطبَّق تحديثان لها معاً بغير ترتيبهما،
     * ومدّةٌ لا يتجاوزها، وما بقي يطبّقه المجدوِل بعدها.
     */
    public function processPending(AggregatorLink $link, int $wait = 0, int $seconds = 50): int
    {
        $lock = Cache::lock('aggregator-inbox:'.$link->company_id, $seconds + 60);

        if (! ($wait > 0 ? rescue(fn () => $lock->block($wait), false, false) : $lock->get())) {
            return 0; // يطبّقها من يحمل القفل الآن، وما فاته يطبّقه المجدوِل
        }

        try {
            $deadline = microtime(true) + $seconds;
            $done = 0;
            $after = 0;

            // دفعةً بعد دفعة حتى لا يبقى شيء: ما وصل وهذا يعمل يُطبَّق معه لا بعد دقيقة
            do {
                $batch = AggregatorEvent::query()->where('result', 'pending')->where('id', '>', $after)
                    ->orderBy('id')->limit(50)->get();

                foreach ($batch as $event) {
                    if (microtime(true) >= $deadline) {
                        return $done;
                    }

                    $after = $event->id;

                    // طبّقه غيرُنا بعد أن قرأناه (انتهى قفلٌ طال عمله): لا يُطبَّق مرّتين
                    if (AggregatorEvent::query()->whereKey($event->id)->value('result') !== 'pending') {
                        continue;
                    }

                    $this->process($link, $event);
                    $done++;
                }
            } while ($batch->isNotEmpty());

            return $done;
        } finally {
            $lock->release();
        }
    }

    /** يطبّق تحديثاً محفوظاً على شحنته، ويكتب ما فُعل به */
    public function process(AggregatorLink $link, AggregatorEvent $event): void
    {
        $payload = (array) $event->payload;

        try {
            DB::transaction(function () use ($link, $event, $payload) {
                $shipment = $this->match($link, $payload, $event->remote_order_id);

                [$result, $message] = $shipment === null
                    ? ['unmatched', 'لا شحنة عندنا مع الوسيط بهذا الرقم.']
                    : $this->apply($link, $shipment, $payload, $event->remote_order_id, $event->status_id,
                        (bool) $event->deliver_done, $event->price, $event->old_price);

                $event->forceFill([
                    'shipment_id' => $shipment?->id,
                    'result' => $result,
                    'message' => $message === null ? null : mb_substr($message, 0, 500),
                    'applied_at' => now(),
                ])->save();
            });
        } catch (Throwable $e) {
            // خطأٌ عندنا: يبقى التحديث محفوظاً ليُرى ويُعاد تطبيقه من السجلّ
            report($e);

            $event->forceFill(['result' => 'error', 'message' => 'تعذّر تطبيقه عندنا.', 'applied_at' => now()])->save();
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
                'exchange_delivered' => $this->exchangeDelivered($shipment, $courier, $price, $note),
                'price_change' => $this->priceChange($shipment, $courier, $price, $note),
                'out_for_delivery' => $shipment->status === ShipmentStatus::InTransit
                    ? $this->move($shipment, $courier, ShipmentStatus::OutForDelivery, $note)
                    : $this->track($shipment, $courier, $note),
                'failed_attempt' => $this->outcome($shipment, $courier, ShipmentStatus::FailedAttempt, $link->reasonFor($statusId, false), $note),
                'return_confirmed', 'return_with_courier' => $this->toReturn($link, $shipment, $courier, $note, $statusId),
                'redelivery' => $this->redeliver($shipment, $courier, $note),
                'return_received' => $this->returnedToUs($link, $shipment, $courier, $note, $statusId),
                'returned_to_merchant' => $this->toMerchant($link, $shipment, $courier, $note, $statusId),
                // في مخزنهم لا مخزننا: «بالمخزن» عندنا لا يكون إلّا بالمسح، فلا تظهر على رفوفنا
                'at_hub' => $this->track($shipment, $courier, 'بالمخزن عند شريك التوصيل — '.$note),
                'postponed' => $this->outcome($shipment, $courier, ShipmentStatus::Postponed, $link->reasonFor($statusId, true), $note),
                default => $this->track($shipment, $courier, $note),
            };
        } catch (ValidationException $e) {
            return ['error', collect($e->errors())->flatten()->implode(' ')];
        }
    }

    /**
     * سلّموا الجديد واستلموا القديم (استبدال). تسجَّل واصلاً كأي تسليم، وقاعدة الاستبدال في
     * ChangeShipmentStatus تجعلها «واصل جزئي» والقديم راجعٌ لتاجره. ولا تصحّ إلّا لشحنةٍ نوعها استبدال:
     * شحنةٌ عاديّة لا يُغيَّر نوعها بقولهم — يُكتب ما قالوه وتبقى كما هي لتقرّر.
     *
     * @return array{0: string, 1: ?string}
     */
    private function exchangeDelivered(Shipment $shipment, Courier $courier, ?int $price, string $note): array
    {
        if ($shipment->wasDelivered()) {
            return ['recorded', 'مسلَّمة من قبل.'];
        }

        if ($shipment->type !== 'exchange') {
            $this->track($shipment, $courier, 'قالوا: سُلّمت واستُلم القديم (استبدال) — '.$note);

            return ['waiting', 'حالتهم تقول استبدالاً، والشحنة عندنا ليست استبدالاً: لم تُسجَّل. قرّر فيها بيدك.'];
        }

        return $this->delivered($shipment, $courier, $price, $note);
    }

    /** @return array{0: string, 1: ?string} */
    private function delivered(Shipment $shipment, Courier $courier, ?int $price, string $note): array
    {
        if ($shipment->wasDelivered()) {
            return ['recorded', 'مسلَّمة من قبل.'];
        }

        $this->toOutForDelivery($shipment, $courier, $note);

        // سلّموها وهي عندنا بعد محاولةٍ فاشلة أو راجعة: المال لا يتحرّك بقولهم بلا قرار. تنتظر المعالجة
        // (إعادة التوصيل ثم «أعد التطبيق» من السجلّ فتُسجَّل واصلة)، ولا تُعدّ خطأً عندنا
        if ($shipment->status !== ShipmentStatus::OutForDelivery) {
            $this->track($shipment, $courier, $note);

            return ['waiting', 'سلّموها وهي «'.$shipment->status->label().'» عندنا: لم تُسجَّل واصلةً. قرّرها من المعالجة'
                .' (إعادة توصيل) ثم «أعد التطبيق».'];
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
    private function toReturn(AggregatorLink $link, Shipment $shipment, Courier $courier, string $note, ?string $statusId = null): array
    {
        if ($shipment->status === ShipmentStatus::Returning) {
            return $this->track($shipment, $courier, $note);
        }

        $this->toOutForDelivery($shipment, $courier, $note);
        $fallback = '';

        if ($shipment->status === ShipmentStatus::OutForDelivery) {
            $reason = $link->reasonFor($statusId, false);
            [$result, $message] = $this->outcome($shipment, $courier, ShipmentStatus::FailedAttempt, $reason, $note);

            if ($result === 'error') {
                return [$result, $message];
            }

            $fallback = $this->fallbackNote($reason, $shipment->company_id);
        }

        if (! in_array($shipment->status, [ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed], true)) {
            return $this->track($shipment, $courier, $note);
        }

        $this->move($shipment, $courier, ShipmentStatus::Returning, $note);

        return ['applied', 'راجع مؤكد — عند المندوب الوسيط حتى يُستلم بالمسح.'.$fallback];
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
    private function returnedToUs(AggregatorLink $link, Shipment $shipment, Courier $courier, string $note, ?string $statusId = null): array
    {
        if ($shipment->status === ShipmentStatus::Returning && $shipment->return_received_at !== null) {
            return ['recorded', 'استُلمت بالمسح من قبل.'];
        }

        // تصير راجعةً أوّلاً إن لم تكن؛ وما لا يصير راجعاً (سُلّم مثلاً) يبقى بما قاله toReturn
        if ($shipment->status !== ShipmentStatus::Returning) {
            $result = $this->toReturn($link, $shipment, $courier, $note, $statusId);

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
    private function toMerchant(AggregatorLink $link, Shipment $shipment, Courier $courier, string $note, ?string $statusId = null): array
    {
        if ($shipment->status === ShipmentStatus::Returned) {
            return ['recorded', 'راجعةٌ لتاجرها من قبل.'];
        }

        if ($shipment->status === ShipmentStatus::Returning && $shipment->return_received_at !== null) {
            return $this->move($shipment, $courier, ShipmentStatus::Returned, $note);
        }

        return $this->returnedToUs($link, $shipment, $courier, $note, $statusId);
    }

    /**
     * «لم يُسلَّم» أو «مؤجّل» بسبب حالتهم (AggregatorLink::reasonFor): المختار لها، أو سبب الربط، أو الاحتياطيّ —
     * ويُكتب في السجلّ أنّه احتياطيّ ليُختار سببها. لا يبقى طردٌ «قيد التوصيل» لأنّ سبباً لم يُختر.
     *
     * @param  array{0: ?int, 1: bool}  $reason
     * @return array{0: string, 1: ?string}
     */
    private function outcome(Shipment $shipment, Courier $courier, ShipmentStatus $to, array $reason, string $note): array
    {
        [$reasonId] = $reason;

        if ($reasonId === null) {
            $this->track($shipment, $courier, $note);

            return ['error', 'لا سبب «'.$to->label().'» مفعّلاً للشركة: أضف سبباً من الإعدادات ثم أعد التطبيق.'];
        }

        $this->toOutForDelivery($shipment, $courier, $note);

        if ($shipment->status !== ShipmentStatus::OutForDelivery) {
            return $this->track($shipment, $courier, $note);
        }

        $errors = CourierOutcomeRules::errors($shipment, $to, ['failure_reason_id' => $reasonId, 'note' => $note], null);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        [$result, $message] = $this->move($shipment, $courier, $to, $note, $reasonId);

        return [$result, $message.$this->fallbackNote($reason, $shipment->company_id)];
    }

    /** يُكتب في السجلّ أنّ السبب احتياطيّ، ليُختار سبب حالتهم في ربط الحالات */
    private function fallbackNote(array $reason, int $companyId): string
    {
        [$reasonId, $fallback] = $reason;

        if (! $fallback || $reasonId === null) {
            return '';
        }

        return ' — بالسبب الاحتياطيّ «'.FailureReason::availableFor($companyId)->whereKey($reasonId)->value('name_ar')
            .'»: اختر سبب هذه الحالة في ربط الحالات.';
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

        // الحالة التي صارت إليها فعلاً: الاستبدال يُطلَب «واصل» ويصير «واصل جزئي»
        return ['applied', $shipment->status->label()];
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
