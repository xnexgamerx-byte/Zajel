<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Models\Courier;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Permissions\Ability;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * تحديث حالة شحناتٍ كثيرة دفعةً واحدة، من القائمة لا من صفحة كل شحنة: ما
 * اختاره الموظّف بيده، أو كل ما يطابق بحثه (يومٌ، مندوب، مرحلة).
 *
 * كل شحنة تمرّ بالمدخل نفسه (ChangeShipmentStatus) فتُفحَص وتُسجَّل وتُقيَّد
 * كما لو غُيّرت وحدها، وما لا يصحّ نقله يُتخطّى بسببه ولا يُسقط الباقي.
 *
 * والحالات هنا ما يصحّ بالجملة وحده: الواصل الجزئي يحتاج مبلغ كل شحنة،
 * والمفقود والتالف قرارٌ لكلٍّ منها، والراجع للتاجر يُسلَّم بإيصالٍ من
 * «الراجع»، والنقل بين الفروع بكشف — فتلك من صفحة الشحنة أو شاشاتها.
 */
class ChangeStatusInBulk
{
    /**
     * أكثر ما يُحدَّث في دفعةٍ واحدة: يومٌ كامل لشركةٍ متوسّطة، وما فوقه يُضيَّق
     * بيومٍ أو مندوب. قيس «واصل» بالجملة على MySQL ‏8: ٢٣ مللي ثانية للشحنة
     * بقيودها (٣٠٠ في سبع ثوانٍ) — فالألف في نحو ٢٥ ثانية.
     */
    public const MAX = 1000;

    public function __construct(
        protected ChangeShipmentStatus $change,
        protected SendOutForDelivery $sendOut,
        protected ReceiveAtHub $receive,
    ) {}

    /** @return array<string, string> الحالة ← نصّها في القائمة، بترتيب رحلة الشحنة */
    public static function targets(): array
    {
        return [
            ShipmentStatus::PickedUp->value       => 'استلمه المندوب',
            ShipmentStatus::AtHub->value          => 'بالمخزن',
            ShipmentStatus::OutForDelivery->value => 'قيد التوصيل — مع مندوب',
            ShipmentStatus::Delivered->value      => 'واصل (المبلغ كاملاً)',
            ShipmentStatus::FailedAttempt->value  => 'لم يُسلَّم',
            ShipmentStatus::Postponed->value      => 'مؤجل',
            ShipmentStatus::Returning->value      => 'راجع',
            ShipmentStatus::Cancelled->value      => 'ملغي',
        ];
    }

    /** الإخراج مع مندوبٍ إسناد، وما عداه تغيير حالة */
    public static function ability(ShipmentStatus $to): string
    {
        return $to === ShipmentStatus::OutForDelivery ? Ability::SHIPMENTS_ASSIGN : Ability::SHIPMENTS_STATUS;
    }

    /** @return array<string, string> ما يملك $user صلاحيته وحده */
    public static function targetsFor(User $user): array
    {
        return array_filter(
            static::targets(),
            fn (string $value) => $user->can(static::ability(ShipmentStatus::from($value))),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * من أيّ حالٍ تنتقل الشحنة إلى $to بالجملة — ليُعَدّ ما سيتحرّك قبل الضغط.
     * «بالمخزن» و«قيد التوصيل» تستلمان ما لم يُستلم بعد أوّلاً (كالمسح
     * والإسناد)، و«بالمخزن» لا تأخذ باقي الواصل الجزئي (يُستلم من «الراجع»).
     *
     * @return list<string>
     */
    public static function sources(ShipmentStatus $to): array
    {
        $from = array_filter(ShipmentStatus::cases(), fn (ShipmentStatus $status) => $status->canMoveTo($to));
        $notYetReceived = [ShipmentStatus::Created, ShipmentStatus::PendingPickup];

        $from = match ($to) {
            ShipmentStatus::AtHub => [...$notYetReceived,
                ...array_filter($from, fn (ShipmentStatus $status) => $status !== ShipmentStatus::PartiallyDelivered)],
            ShipmentStatus::OutForDelivery => [...$notYetReceived, ...$from],
            default => $from,
        };

        return array_values(array_unique(array_map(fn (ShipmentStatus $status) => $status->value, $from)));
    }

    /**
     * @param  Collection<int, Shipment>  $shipments  ما يراه $actor وحده — يُصفّى قبل الاستدعاء
     * @param  array{courier?: Courier, failure_reason_id?: int, note?: ?string}  $options
     * @return array{moved: list<string>, received: list<string>, skipped: array<string, string>}
     */
    public function handle(Collection $shipments, ShipmentStatus $to, User $actor, array $options = []): array
    {
        $note = filled($options['note'] ?? null) ? $options['note'] : 'تحديث جماعي';

        if ($to === ShipmentStatus::OutForDelivery) {
            return $this->sendOut->handle($shipments, $options['courier'], $actor, $note);
        }

        // «بالمخزن» كالمسح عند الباب: يُستلم ما لم يُستلم، ويُستلم الراجع من مندوبه
        if ($to === ShipmentStatus::AtHub) {
            $result = $this->receive->handle($shipments->pluck('id')->all(), $actor, $note);

            return ['moved' => $result['received'], 'received' => [], 'skipped' => $result['skipped']];
        }

        $moved = [];
        $skipped = [];

        foreach ($shipments as $shipment) {
            if (! $shipment->status->canMoveTo($to)) {
                $skipped[$shipment->number] = $shipment->status->label();

                continue;
            }

            try {
                $this->change->handle($shipment, $to, $actor, array_filter([
                    'failure_reason_id' => $to === ShipmentStatus::FailedAttempt ? ($options['failure_reason_id'] ?? null) : null,
                    'note'              => $note,
                ], fn ($value) => $value !== null));
            } catch (ValidationException $e) {
                // سبقه أحدٌ إليها بين الاختيار والحفظ: تُتخطّى بسببها ولا يسقط الباقي
                $skipped[$shipment->number] = collect($e->errors())->flatten()->first();

                continue;
            }

            $moved[] = $shipment->number;
        }

        return ['moved' => $moved, 'received' => [], 'skipped' => $skipped];
    }
}
