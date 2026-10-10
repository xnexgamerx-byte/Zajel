<?php

namespace App\Http\Requests;

use App\Enums\ShipmentStatus;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Hub;
use App\Models\Shipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeStatusRequest extends FormRequest
{
    /** «إعادة توصيل» و«واصل إجباري» في قائمة «الحالة الجديدة» — لا حالتين بل طريقين إليهما */
    public const REDELIVER = 'redeliver';

    public const FORCE_DELIVERED = 'forced_delivered';

    public const SHORTCUTS = [self::REDELIVER, self::FORCE_DELIVERED];

    /** تُعاد للتوصيل من هنا: محاولةٌ فشلت، أو تأجّلت، أو رجعت للمخزن بعد محاولة */
    public static function canRedeliver(Shipment $shipment): bool
    {
        return in_array($shipment->status, [ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed], true)
            || ($shipment->status === ShipmentStatus::AtHub && (int) $shipment->attempts_count > 0);
    }

    /** «واصل إجباري» من القائمة: لمن يملك صلاحيته، ولشحنةٍ لم تنتهِ ولم تصل */
    public static function canForceDelivered(Shipment $shipment, $user): bool
    {
        return $user?->can('control.force')
            && $shipment->status->isOpen()
            && $shipment->status !== ShipmentStatus::Delivered;
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // ومعها خياران في القائمة نفسها (docs/plan/53): «إعادة توصيل» و«واصل إجباري»
            'status'            => ['required', Rule::in([...array_column(ShipmentStatus::cases(), 'value'), ...self::SHORTCUTS])],
            'courier_id'        => ['nullable', 'integer'],
            'hub_id'            => ['nullable', 'integer'],
            'failure_reason_id' => ['nullable', 'integer'],
            'collected_amount'  => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'note'              => ['nullable', 'string', 'max:500'],
            'force'             => ['nullable', 'boolean'],
            // إعادة توصيل الراجع بطلب التاجر: قرارٌ صريح بسببه (docs/plan/38)
            'retry'             => ['nullable', 'boolean'],
            'forced_reason'     => ['required_if:force,1', 'nullable', 'string', 'max:255'],
            'force_delivered_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var Shipment $shipment */
            $shipment = $this->route('shipment');

            if ($this->input('status') === self::REDELIVER) {
                $this->checkRedeliver($validator, $shipment);

                return;
            }

            if ($this->input('status') === self::FORCE_DELIVERED) {
                $this->checkForceDelivered($validator, $shipment);

                return;
            }

            $to = ShipmentStatus::tryFrom((string) $this->input('status'));

            if (! $to) {
                return;
            }

            // الانتقال يُفحَص هنا أيضاً، لا في الخدمة وحدها: الرسالة تصل
            // للمستخدم كخطأ حقل لا كصفحة خطأ. والإجبار يتخطّاه بسبب مكتوب.
            if ($shipment->status === ShipmentStatus::Returning && $to === ShipmentStatus::AtHub && ! $this->boolean('force')) {
                if (! $this->boolean('retry')) {
                    $validator->errors()->add('status', 'الشحنة راجعةٌ لتاجرها: إعادتها للتوصيل قرارٌ بطلب التاجر.');
                } elseif (! filled($this->input('note'))) {
                    $validator->errors()->add('note', 'اكتب سبب إعادة التوصيل: من طلبها من التاجر ولماذا.');
                }
            }

            if (! $this->boolean('force') && ! $shipment->status->canMoveTo($to)) {
                $validator->errors()->add(
                    'status',
                    "لا يمكن الانتقال من «{$shipment->status->label()}» إلى «{$to->label()}».",
                );
            }

            /*
            | الاستعلامات هنا مفلترة بالشركة، فمندوب شركة أخرى غير موجود أصلاً.
            |
            | والوجود وحده لا يكفي: مسار الإسناد الجَماعي يفرض
            | delivering()->active() وهذا المسار كان يكتفي بـ exists —
            | فمندوب استلام أو موقوف يُسنَد إليه توصيلٌ من شاشة الشحنة
            | الواحدة. القائمة المنسدلة لا تعرضه، لكن «إخفاء الزرّ ليس
            | منعاً»، والطلب يُصاغ بيد. ومندوب الاستلام عمولته وتسويته
            | دورةٌ أخرى، فتوصيلةٌ باسمه لا تُحاسَب في أيّ منهما.
            */
            if ($this->courier_id) {
                // ومندوبٌ من فرع الموظّف إن كان مقيَّداً بفرع
                $courier = Courier::whereKey($this->courier_id)->visibleTo($this->user())->first();

                if (! $courier) {
                    $validator->errors()->add('courier_id', 'المندوب غير موجود.');
                } elseif ($courier->status !== 'active') {
                    $validator->errors()->add('courier_id', "المندوب «{$courier->name}» غير مفعّل.");
                } elseif (! $courier->delivers()) {
                    $validator->errors()->add('courier_id', "المندوب «{$courier->name}» مندوب استلام لا توصيل.");
                }
            }

            if ($this->hub_id && ! Hub::whereKey($this->hub_id)->exists()) {
                $validator->errors()->add('hub_id', 'المركز غير موجود.');
            }

            if ($to === ShipmentStatus::OutForDelivery && ! $this->courier_id && ! $shipment->delivery_courier_id) {
                $validator->errors()->add('courier_id', 'اختر المندوب قبل إخراج الشحنة للتوصيل.');
            }

            if ($to === ShipmentStatus::FailedAttempt) {
                $reason = $this->failure_reason_id
                    ? FailureReason::availableFor($this->user()->company_id)->whereKey($this->failure_reason_id)->first()
                    : null;

                if (! $reason) {
                    $validator->errors()->add('failure_reason_id', 'سبب الفشل مطلوب — بلا سبب مصنَّف لا تقرير ولا محاسبة.');
                } elseif ($reason->requires_note && ! trim((string) $this->note)) {
                    $validator->errors()->add('note', "السبب «{$reason->name_ar}» يتطلّب ملاحظة توضيحية.");
                }
            }

            // تسليم جزئي بلا مبلغ = رقم مخترع في حساب التاجر
            if ($to === ShipmentStatus::PartiallyDelivered && $this->input('collected_amount') === null) {
                $validator->errors()->add('collected_amount', 'أدخل المبلغ المحصَّل فعلاً في التسليم الجزئي.');
            }

            if ($this->input('collected_amount') !== null
                && (int) $this->input('collected_amount') > $shipment->cod_amount) {
                $validator->errors()->add('collected_amount', 'المبلغ المحصَّل أكبر من المطلوب من الزبون.');
            }
        });
    }

    protected function checkRedeliver($validator, Shipment $shipment): void
    {
        if (! self::canRedeliver($shipment)) {
            $validator->errors()->add('status', "الشحنة «{$shipment->status->label()}»: لا تُعاد للتوصيل من هنا.");

            return;
        }

        // المتعثّرة تخرج مع مندوبها؛ والمؤجّلة والراجعة للمخزن تحتاجه
        if ($shipment->status !== ShipmentStatus::FailedAttempt && ! $this->courier_id && ! $shipment->delivery_courier_id) {
            $validator->errors()->add('courier_id', 'اختر المندوب الذي يعيد توصيلها.');
        }

        if ($this->courier_id) {
            $courier = Courier::whereKey($this->courier_id)->visibleTo($this->user())->first();

            if (! $courier || $courier->status !== 'active' || ! $courier->delivers()) {
                $validator->errors()->add('courier_id', 'المندوب غير موجود أو غير مفعّل أو ليس مندوب توصيل.');
            }
        }
    }

    protected function checkForceDelivered($validator, Shipment $shipment): void
    {
        if (! self::canForceDelivered($shipment, $this->user())) {
            $validator->errors()->add('status', $this->user()?->can('control.force')
                ? "الشحنة «{$shipment->status->label()}»: لا تُعلَن واصلة."
                : '«واصل إجباري» لمن يملك صلاحيته.');

            return;
        }

        if (! filled(trim((string) $this->input('force_delivered_reason')))) {
            $validator->errors()->add('force_delivered_reason', 'اكتب سبب الواصل الإجباري — يُسجَّل باسمك.');
        }

        if ($this->input('collected_amount') === null) {
            $validator->errors()->add('collected_amount', 'أدخل المبلغ المحصَّل فعلاً.');
        } elseif ((int) $this->input('collected_amount') > $shipment->cod_amount) {
            $validator->errors()->add('collected_amount', 'المبلغ المحصَّل أكبر من المطلوب من الزبون.');
        }
    }

    public function attributes(): array
    {
        return [
            'status'            => 'الحالة',
            'courier_id'        => 'المندوب',
            'hub_id'            => 'المركز',
            'failure_reason_id' => 'سبب الفشل',
            'collected_amount'  => 'المبلغ المحصَّل',
            'note'              => 'الملاحظة',
            'forced_reason'     => 'سبب الإجبار',
        ];
    }
}
