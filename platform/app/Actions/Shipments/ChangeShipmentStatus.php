<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * المدخل الوحيد لتغيير حالة الشحنة.
 *
 * لا يُسمح بكتابة $shipment->status = ... في أي مكان آخر:
 * كل تغيير يمرّ من هنا فيُتحقَّق من شرعيّة الانتقال ويُسجَّل حدثه،
 * وإلّا صار سجلّ التتبّع فيه ثقوب وصارت التقارير تكذب.
 */
class ChangeShipmentStatus
{
    public function __construct(protected Ledger $ledger) {}

    public function handle(
        Shipment $shipment,
        ShipmentStatus $to,
        ?User $actor = null,
        array $options = [],
    ): Shipment {
        return DB::transaction(function () use ($shipment, $to, $actor, $options) {
            /*
            | الحال من القاعدة بعد قفل الصفّ، لا من النسخة التي بيد المستدعي.
            |
            | كان «من أين» يُقرأ من الذاكرة ويُفحَص خارج المعاملة. فنقرتان على
            | «سُلِّمت» تقيّدان التسليم مرّتين (النقد والعمولة ومستحقّ التاجر)،
            | ونسخةٌ قديمة تقول «مع المندوب» تُسجّل محاولةً فاشلة على شحنةٍ
            | سُلِّمت وقُيّد مالها. الآن النقرة الثانية لا تفعل شيئاً — الحال
            | صارت ما طُلب — والانتقال من حالٍ مضت يُرفض.
            */
            $shipment->setRawAttributes(
                Shipment::query()->lockForUpdate()->findOrFail($shipment->id)->getAttributes(),
                true,
            );
            $shipment->setRelations([]);

            return $this->transition($shipment, $to, $actor, $options);
        });
    }

    protected function transition(Shipment $shipment, ShipmentStatus $to, ?User $actor, array $options): Shipment
    {
        $from = $shipment->status;

        /*
        | الاستبدال (الوثيقة ٣١): يُسلَّم الجديد ويُستلم القديم. يُحسب شحنةً واصلةً بأجرة التوصيل
        | وعمولة المندوب كاملتين، والقديم راجعٌ جزئيّ لتاجره بلا أجرة راجعٍ ولا عمولة إرجاع —
        | فهو «واصل جزئي» (الوثيقة ٢٤) أيّاً كان من سجّله: المندوب، أو الموظّف، أو الجملة.
        */
        if ($to === ShipmentStatus::Delivered && $shipment->type === 'exchange') {
            $to = ShipmentStatus::PartiallyDelivered;
            $options['note'] = trim('استبدال: سُلِّم الجديد، والقديم راجعٌ لتاجره. '.($options['note'] ?? ''));
        }

        if ($from === $to) {
            return $shipment;
        }

        /*
        | ما سُلِّم بعضه لا يُسلَّم ثانيةً: باقي الواصل الجزئي راجعٌ لتاجره (الوثيقة ٢٤).
        | تسليمٌ ثانٍ يقيّد مستحقّ الشحنة وعمولتها مرّتين — ولا يفتحه الإجبار.
        */
        if ($shipment->wasDelivered()
            && in_array($to, [ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered, ShipmentStatus::PartiallyDelivered], true)) {
            throw ValidationException::withMessages([
                'status' => "سُلِّم بعض الشحنة {$shipment->number} من قبل، وباقيها راجعٌ لتاجرها فلا يخرج للتوصيل ثانيةً.",
            ]);
        }

        /*
        | «واصل إجباري»: تسليم من غير مسار الحالات الطبيعي.
        |
        | أشيع تلاعب في هذا المجال أن يُعلَن التسليم من غير تسليم، ثم
        | يُسوّى النقد لاحقاً «عند الجباية». فالانتقال غير المسموح لا
        | يُفتَح إلّا بسبب مكتوب باسم صاحبه، ويبقى موسوماً في الشحنة
        | وفي تقرير يُقرأ.
        */
        if (! empty($options['force'])) {
            if (empty($options['forced_reason'])) {
                throw ValidationException::withMessages([
                    'forced_reason' => 'التسليم الإجباري لا يمرّ بلا سبب مكتوب.',
                ]);
            }

            if (in_array($from, ShipmentStatus::terminal(), true)) {
                throw ValidationException::withMessages([
                    'status' => "الشحنة في حالة نهائية «{$from->label()}» فلا تُفتَح بالإجبار.",
                ]);
            }
        } elseif (! $from->canMoveTo($to)) {
            throw ValidationException::withMessages([
                'status' => "انتقال غير مسموح: من «{$from->label()}» إلى «{$to->label()}».",
            ]);
        }

        // المعلَّقة للمراجعة لا تخرج مع مندوبٍ حتى تُجاز (والإجباريّ يمرّ بسببه المكتوب)
        if ($to === ShipmentStatus::OutForDelivery && empty($options['force']) && $shipment->isHeldForReview()) {
            throw ValidationException::withMessages([
                'status' => "الشحنة {$shipment->number} تحت المراجعة: تُجاز أوّلاً من «تحت المراجعة».",
            ]);
        }

        // أجرةٌ قُبضت مقدّماً لا تُلغى شحنتها: الإلغاء لا يُرجع للتاجر شيئاً، والراجع
        // يُرجع له ما دفع بعد أجرته (ReceivePrepaidFees)
        if ($to === ShipmentStatus::Cancelled && (int) $shipment->prepaid_amount > 0) {
            throw ValidationException::withMessages([
                'status' => "قُبضت أجرة الشحنة {$shipment->number} مقدّماً، فلا تُلغى — تُرجَع لتاجرها راجعاً فيُحسب له ما دفع.",
            ]);
        }

        // «راجعة للتاجر» تعني أن التاجر استلمها، وعندها تُقيَّد أجرة الراجع
        // عليه. تسليم طرد ما زال في حقيبة المندوب هو الخلاف نفسه الذي
        // يُبنى هذا المسار لمنعه.
        if ($to === ShipmentStatus::Returned && $shipment->return_received_at === null) {
            throw ValidationException::withMessages([
                'status' => "لم تُستلم الشحنة {$shipment->number} من المندوب بعد، فلا تُسلَّم للتاجر.",
            ]);
        }

        return DB::transaction(function () use ($shipment, $from, $to, $actor, $options) {
            $attributes = [
                'status'            => $to,
                'status_changed_at' => now(),
            ];

            match ($to) {
                ShipmentStatus::PickedUp      => $attributes['picked_up_at'] = now(),
                ShipmentStatus::Delivered,
                ShipmentStatus::PartiallyDelivered => $attributes['delivered_at'] = now(),
                ShipmentStatus::Returned      => $attributes['returned_at'] = now(),
                ShipmentStatus::Cancelled     => $attributes['cancelled_at'] = now(),
                default                       => null,
            };

            // طرد في المخزن قُرّر إرجاعه لم يغادر أصلاً: استلامه من المندوب
            // خطوة لا وجود لها، فلا تُفرض على الموظّف.
            if ($to === ShipmentStatus::Returning && $from === ShipmentStatus::AtHub) {
                $attributes['return_received_at'] = now();
                $attributes['return_received_by_user_id'] = $actor?->id;
            }

            if (! empty($options['force'])) {
                $attributes['is_forced'] = true;
                $attributes['forced_reason'] = $options['forced_reason'];
                $attributes['forced_by_user_id'] = $actor?->id;
            }

            if ($to === ShipmentStatus::FailedAttempt) {
                $attributes['attempts_count'] = $shipment->attempts_count + 1;
            }

            /*
            | «إعادة توصيل» (docs/plan/38): قرار المعالجة يُعلِّمها، فتُعرض في خانتها لا مع ما
            | خرج أوّل مرّة. وتفشل ثانيةً أو تؤجَّل فيُمحى: لها قرارٌ جديد ينتظر.
            */
            if (! empty($options['redelivery'])) {
                $attributes['redelivery_at'] = now();
            } elseif (in_array($to, [ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed], true)) {
                $attributes['redelivery_at'] = null;
            }

            /*
            | «راجع مؤكد»: قرارٌ بالإرجاع بعد محاولةٍ فاشلة أو تأجيل — من الكول سنتر أو التاجر
            | أو موظّف — أو لشحنةٍ في المخزن. يُكتب بصاحبه، وتسير بعده راجعاً لا شحنةً جديدة.
            | وباقي الواصل الجزئي يرجع بحكم التسليم لا بقرار، فلا يُعلَّم.
            */
            if ($to === ShipmentStatus::Returning
                && in_array($from, [ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed, ShipmentStatus::AtHub], true)) {
                $attributes['return_confirmed_at'] = now();
                $attributes['return_confirmed_by_user_id'] = $actor?->id;
                $attributes['redelivery_at'] = null;
            }

            if (isset($options['failure_reason_id'])) {
                $attributes['last_failure_reason_id'] = $options['failure_reason_id'];
            }

            if (isset($options['courier_id'])) {
                $attributes['delivery_courier_id'] = $options['courier_id'];
                $attributes['assigned_at'] = now();
            }

            if (isset($options['hub_id'])) {
                $attributes['hub_id'] = $options['hub_id'];
            }

            // التأجيل إلى موعدٍ اتُّفق عليه مع الزبون
            if (array_key_exists('scheduled_at', $options)) {
                $attributes['scheduled_at'] = $options['scheduled_at'];
            }

            // المبلغ المحصَّل يُثبَّت عند التسليم ولا يُعدَّل بعده،
            // ومعه تُجمَّد عمولة المندوب ويُعاد حساب مستحقّ التاجر على
            // أساس ما حُصِّل فعلاً لا ما كان مطلوباً.
            if (in_array($to, [ShipmentStatus::Delivered, ShipmentStatus::PartiallyDelivered], true)) {
                $attributes['collected_amount'] = array_key_exists('collected_amount', $options)
                    ? (int) $options['collected_amount']
                    : $shipment->cod_amount;

                $courier = $shipment->deliveryCourier;
                $attributes['courier_commission'] = $courier?->payForDelivery($shipment) ?? 0;

                // وما دفعه التاجر مقدّماً من الأجور يعود إليه هنا: لا تُخصم أجرةٌ دُفعت
                $attributes['merchant_due'] = ($shipment->fees_paid_by === 'customer'
                    ? $attributes['collected_amount'] - $shipment->cod_fee + $shipment->discount
                    : $attributes['collected_amount'] - $shipment->total_fees) + (int) $shipment->prepaid_amount;

                // الواصل الجزئي: أجرة التوصيل كاملةً (أعلاه)، وباقيه يرجع لتاجره راجعاً عاديّاً
                // بلا أجرة راجع — قرار الشركة (الوثيقة ٢٤)
                if ($to === ShipmentStatus::PartiallyDelivered) {
                    $attributes['return_fee'] = 0;
                }
            }

            if ($to === ShipmentStatus::Returned) {
                // باقي الواصل الجزئي يرجع بلا عمولة إرجاع: عمولة التوصيل قُيِّدت كاملةً
                // عند التسليم وتبقى عمولةَ الشحنة (الوثيقة ٢٤)
                if (! $shipment->wasDelivered()) {
                    $courier = $shipment->deliveryCourier;
                    $attributes['courier_commission'] = $courier?->commission_per_return ?? 0;
                }
                /*
                | أجرة الراجع عليه، فوق ما قُيِّد له عن الشحنة من قبل — وما دفعه مقدّماً
                | إن لم يُقيَّد بعد. والمعتاد ألّا يكون قبلها شيء؛ أمّا باقي الواصل الجزئي
                | فقد قُيِّد له ما بيع منه عند التسليم وأجرة رجوعه صفر: يبقى مستحقّه كما كان.
                | (كان يُمحى هنا ما بيع فتدفع تسويته أجرة الراجع وحدها والدفتر يحفظ له ما بيع.)
                */
                $attributes['merchant_due'] = $this->ledger->postedForShipment($shipment)
                    - $shipment->return_fee
                    + ($this->ledger->prepaidPosted($shipment) ? 0 : (int) $shipment->prepaid_amount);
            }

            $shipment->fill($attributes)->save();

            ShipmentEvent::create([
                'shipment_id'       => $shipment->id,
                'from_status'       => $from->value,
                'to_status'         => $to->value,
                'event_type'        => empty($options['force']) ? 'status_change' : 'forced_status',
                'actor_type'        => $options['actor_type'] ?? ($actor ? 'user' : 'system'),
                'actor_id'          => $actor?->id,
                'actor_name'        => $actor?->name,
                // مندوب الحدث وحده بلا إسناد: مندوب النقل بين الفروع على «بالطريق» (RunManifest)
                'courier_id'        => $options['event_courier_id'] ?? $options['courier_id'] ?? $shipment->delivery_courier_id,
                'hub_id'            => $options['hub_id'] ?? $shipment->hub_id,
                'failure_reason_id' => $options['failure_reason_id'] ?? null,
                'amount'            => $attributes['collected_amount'] ?? null,
                'note'              => empty($options['force'])
                    ? ($options['note'] ?? null)
                    : 'واصل إجباري — '.$options['forced_reason'],
                'lat'               => $options['lat'] ?? null,
                'lng'               => $options['lng'] ?? null,
                'ip'                => request()->ip(),
            ]);

            $shipment->refresh();

            // خرجت من يد المندوب: طلباته لتغيير المبلغ تُحسم معها (docs/plan/30)
            if ($from === ShipmentStatus::OutForDelivery) {
                ShipmentTickets::settle($shipment, $to, $options['ticket_id'] ?? null);
            }

            // المال يتحرّك بعد ثبوت الحالة، وداخل المعاملة نفسها:
            // إمّا أن تُسجَّل الحالة والحركة معاً أو لا يُكتب شيء.
            match ($to) {
                ShipmentStatus::Delivered,
                ShipmentStatus::PartiallyDelivered => $this->ledger->recordDelivery($shipment, $actor),
                ShipmentStatus::Returned           => $this->ledger->recordReturn($shipment, $actor),
                default                            => null,
            };

            return $shipment->refresh();
        });
    }
}
