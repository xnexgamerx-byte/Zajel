<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Models\City;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\DuplicateDetector;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تصحيح بيانات شحنةٍ لم تُقفَل.
 *
 * رقمٌ خاطئ في هاتف الزبون يعني شحنةً لا تُسلَّم، وكان لا يُصحَّح بعد الحفظ.
 * والتصحيح آمنٌ ما دام المال لم يُقيَّد: الدفتر يُكتب عند التسليم أو الرجوع
 * (Ledger::recordDelivery، recordReturn)، فقبلهما المبالغ أرقامٌ على الشحنة
 * لا قيودٌ في حساب أحد.
 *
 * والأجور لا تُعاد حسابها إلا إن تغيّر ما يُسعَّر به: تصحيح هاتفٍ لا يجوز أن
 * يبدّل أجرةً وضعها موظّفٌ يدوياً، ولا أن يطبّق تسعيرةً عُدِّلت بعد الإنشاء.
 *
 * وكل تعديلٍ حدثٌ في سجلّ الشحنة: ما كان، وما صار، ومن غيّره.
 */
class UpdateShipment
{
    /** ما يُعدَّل، بأسمائه في السجلّ. */
    public const FIELDS = [
        'recipient_name'      => 'اسم المستلم',
        'recipient_phone'     => 'هاتف المستلم',
        'recipient_phone_alt' => 'الهاتف البديل',
        'governorate_id'      => 'المحافظة',
        'city_id'             => 'المنطقة',
        'address'             => 'العنوان',
        'landmark'            => 'أقرب نقطة دالّة',
        'description'         => 'وصف المحتوى',
        'pieces_count'        => 'عدد القطع',
        'weight_grams'        => 'الوزن',
        'is_fragile'          => 'قابل للكسر',
        'allow_open'          => 'يُسمح بالفتح',
        'notes'               => 'ملاحظات للمندوب',
        'merchant_reference'  => 'رقم طلب التاجر',
        'cod_amount'          => 'المبلغ المطلوب',
        'fees_paid_by'        => 'من يدفع الأجرة',
        'delivery_fee'        => 'أجرة التوصيل',
        'extra_fee'           => 'رسوم إضافية',
        'discount'            => 'الخصم',
        'cod_fee'             => 'عمولة التحصيل',
        'return_fee'          => 'أجرة الراجع',
        'total_fees'          => 'مجموع الأجور',
        'merchant_due'        => 'مستحقّ التاجر',
    ];

    public function __construct(
        protected PricingService $pricing,
        protected DuplicateDetector $duplicates,
    ) {}

    /** تُعدَّل ما دامت مفتوحة ولم يُقيَّد منها مال: التسليم الجزئي قُيِّد. */
    public static function editable(Shipment $shipment): bool
    {
        return $shipment->status->isOpen() && $shipment->status !== ShipmentStatus::PartiallyDelivered;
    }

    /** المحافظة تتغيّر قبل المخزن: بعده الشحنة في كيسٍ أو كشفٍ إلى وجهتها. */
    public static function reroutable(Shipment $shipment): bool
    {
        return in_array($shipment->status, [
            ShipmentStatus::Created, ShipmentStatus::PendingPickup, ShipmentStatus::PickedUp,
        ], true);
    }

    public function handle(Shipment $shipment, array $data, ?User $actor = null): Shipment
    {
        return DB::transaction(function () use ($shipment, $data, $actor) {
            // القفل ثم إعادة الفحص: مندوبٌ يسلّمها في اللحظة نفسها يسبق التعديل أو يلحقه، لا يتداخلان
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            if (! self::editable($shipment)) {
                throw ValidationException::withMessages([
                    'shipment' => 'لا تُعدَّل شحنةٌ بعد تسليمها أو إرجاعها أو إلغائها — مالها قُيِّد في الحسابات.',
                ]);
            }

            $governorateId = (int) $data['governorate_id'];

            if ($governorateId !== (int) $shipment->governorate_id && ! self::reroutable($shipment)) {
                throw ValidationException::withMessages([
                    'governorate_id' => 'لا تتغيّر المحافظة بعد دخول الشحنة المخزن — هي في طريقها إلى وجهتها.',
                ]);
            }

            $cityId = isset($data['city_id']) && $data['city_id'] !== '' ? (int) $data['city_id'] : null;
            $weight = (int) ($data['weight_grams'] ?? 0);
            $cod = (int) $data['cod_amount'];
            $feesPaidBy = $data['fees_paid_by'];
            $extraFee = (int) ($data['extra_fee'] ?? 0);
            $discount = (int) ($data['discount'] ?? 0);

            $rerouted = $governorateId !== (int) $shipment->governorate_id
                || $cityId !== ($shipment->city_id === null ? null : (int) $shipment->city_id)
                || $weight !== (int) $shipment->weight_grams;

            $recharged = $rerouted
                || $cod !== (int) $shipment->cod_amount
                || $feesPaidBy !== $shipment->fees_paid_by;

            $quote = $recharged
                ? $this->pricing->quote(
                    merchant: $shipment->merchant,
                    toGovernorateId: $governorateId,
                    toCityId: $cityId,
                    weightGrams: $weight,
                    codAmount: $cod,
                    feesPaidBy: $feesPaidBy,
                )
                : null;

            // الأجرة المكتوبة تغلب؛ والفارغة تبقى كما هي إلا إن تغيّرت الوجهة أو الوزن
            $deliveryFee = isset($data['delivery_fee']) && $data['delivery_fee'] !== null && $data['delivery_fee'] !== ''
                ? (int) $data['delivery_fee']
                : ($rerouted ? $quote['delivery_fee'] : (int) $shipment->delivery_fee);

            $codFee = $recharged ? $quote['cod_fee'] : (int) $shipment->cod_fee;
            $returnFee = $rerouted ? $quote['return_fee'] : (int) $shipment->return_fee;

            $shipment->fill([
                'recipient_name'      => $data['recipient_name'],
                'recipient_phone'     => $data['recipient_phone'],
                'recipient_phone_alt' => $data['recipient_phone_alt'] ?? null,
                'governorate_id'      => $governorateId,
                'city_id'             => $cityId,
                'address'             => $data['address'],
                'landmark'            => $data['landmark'],
                'description'         => $data['description'] ?? null,
                'pieces_count'        => (int) ($data['pieces_count'] ?? 1),
                'weight_grams'        => $weight,
                'is_fragile'          => (bool) ($data['is_fragile'] ?? false),
                'allow_open'          => (bool) ($data['allow_open'] ?? false),
                'notes'               => $data['notes'] ?? null,
                'merchant_reference'  => $data['merchant_reference'] ?? null,
                'cod_amount'          => $cod,
                'fees_paid_by'        => $feesPaidBy,
                'delivery_fee'        => $deliveryFee,
                'extra_fee'           => $extraFee,
                'discount'            => $discount,
                'cod_fee'             => $codFee,
                'return_fee'          => $returnFee,
                ...PricingService::totals($cod, $feesPaidBy, $deliveryFee, $extraFee, $codFee, $discount),
            ]);

            $changes = [];
            foreach (array_keys(self::FIELDS) as $field) {
                if ($shipment->isDirty($field)) {
                    $changes[$field] = ['from' => $shipment->getOriginal($field), 'to' => $shipment->getAttribute($field)];
                }
            }

            if ($changes === []) {
                return $shipment;
            }

            if ($shipment->isDirty('cod_amount')) {
                $shipment->payment_type = $cod > 0 ? 'cod' : 'prepaid';
            }

            $shipment->save();

            // بصمة المكرّر من البيانات الجديدة: هاتفٌ صُحِّح قد يصير تكراراً لشحنةٍ أخرى أو يكفّ عن ذلك
            $shipment->forceFill(['dedupe_hash' => $this->duplicates->hashFor($shipment)])->save();

            ShipmentEvent::create([
                'shipment_id' => $shipment->id,
                'from_status' => $shipment->status->value,
                'to_status'   => $shipment->status->value,
                'event_type'  => 'edited',
                'actor_type'  => $actor ? 'user' : 'system',
                'actor_id'    => $actor?->id,
                'actor_name'  => $actor?->name,
                'note'        => $this->describe($changes),
                'meta'        => ['changes' => $changes],
                'ip'          => request()->ip(),
            ]);

            return $shipment->refresh();
        });
    }

    /**
     * «الهاتف: …567 ← …890 · المبلغ: 50,000 ← 60,000» — ما يقرؤه الموظّف في السجلّ.
     * الأرقام الكاملة في meta؛ النصّ الظاهر يُخفي الهواتف كما تُخفيها الشاشات.
     *
     * @param  array<string, array{from:mixed, to:mixed}>  $changes
     */
    private function describe(array $changes): string
    {
        $parts = [];

        foreach ($changes as $field => ['from' => $from, 'to' => $to]) {
            // المشتقّات تتبع أصولها: يكفي أن يُرى المبلغ والأجرة ومستحقّ التاجر
            if (in_array($field, ['cod_fee', 'return_fee', 'total_fees'], true)) {
                continue;
            }

            $parts[] = self::FIELDS[$field].': '.$this->show($field, $from).' ← '.$this->show($field, $to);
        }

        return 'تعديل البيانات — '.implode(' · ', $parts);
    }

    private function show(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match (true) {
            in_array($field, ['recipient_phone', 'recipient_phone_alt'], true) => '…'.substr((string) $value, -3),
            $field === 'governorate_id' => (string) Governorate::whereKey($value)->value('name_ar'),
            $field === 'city_id'        => (string) City::whereKey($value)->value('name_ar'),
            $field === 'fees_paid_by'   => $value === 'customer' ? 'الزبون' : 'التاجر',
            in_array($field, ['is_fragile', 'allow_open'], true) => $value ? 'نعم' : 'لا',
            in_array($field, ['cod_amount', 'delivery_fee', 'extra_fee', 'discount', 'merchant_due'], true)
                => number_format((int) $value),
            default => mb_strimwidth((string) $value, 0, 40, '…'),
        };
    }
}
