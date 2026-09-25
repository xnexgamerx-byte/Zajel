<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\DuplicateDetector;
use App\Services\PricingService;
use App\Services\SequenceGenerator;
use Illuminate\Support\Facades\DB;

/**
 * إنشاء شحنة: رقم وصل + تسعير + أول حدث في السجلّ — كلّها في معاملة واحدة.
 * إمّا أن تنجح كلّها أو لا يُكتب شيء؛ لا شحنة بلا رقم ولا شحنة بلا تاريخ.
 */
class CreateShipment
{
    public function __construct(
        protected SequenceGenerator $sequences,
        protected PricingService $pricing,
        protected DuplicateDetector $duplicates,
    ) {}

    public function handle(array $data, ?User $actor = null): Shipment
    {
        return DB::transaction(function () use ($data, $actor) {
            $merchant = Merchant::findOrFail($data['merchant_id']);

            $weight = (int) ($data['weight_grams'] ?? 0);
            $cod = (int) ($data['cod_amount'] ?? 0);
            $feesPaidBy = $data['fees_paid_by'] ?? 'merchant';

            $quote = $this->pricing->quote(
                merchant: $merchant,
                toGovernorateId: (int) $data['governorate_id'],
                toCityId: isset($data['city_id']) ? (int) $data['city_id'] : null,
                weightGrams: $weight,
                codAmount: $cod,
                feesPaidBy: $feesPaidBy,
                extraFee: (int) ($data['extra_fee'] ?? 0),
                discount: (int) ($data['discount'] ?? 0),
            );

            // التسعير اليدوي يغلب المحسوب عندما يُدخله المستخدم صراحةً
            $deliveryFee = array_key_exists('delivery_fee', $data) && $data['delivery_fee'] !== null
                ? (int) $data['delivery_fee']
                : $quote['delivery_fee'];

            $extraFee = (int) ($data['extra_fee'] ?? $quote['extra_fee']);
            $discount = (int) ($data['discount'] ?? 0);
            $codFee = $quote['cod_fee'];
            ['total_fees' => $totalFees, 'merchant_due' => $merchantDue] =
                PricingService::totals($cod, $feesPaidBy, $deliveryFee, $extraFee, $codFee, $discount);

            $number = $this->sequences->next('shipment');

            $shipment = Shipment::create([
                'branch_id'           => $data['branch_id'] ?? $merchant->branch_id ?? $actor?->branch_id,
                'merchant_id'         => $merchant->id,
                'number'              => $number,
                'barcode'             => $data['barcode'] ?? $number,
                'merchant_reference'  => $data['merchant_reference'] ?? null,
                'type'                => $data['type'] ?? 'delivery',

                'recipient_name'      => $data['recipient_name'],
                'recipient_phone'     => $data['recipient_phone'],
                'recipient_phone_alt' => $data['recipient_phone_alt'] ?? null,
                'governorate_id'      => $data['governorate_id'],
                'city_id'             => $data['city_id'] ?? null,
                'address'             => $data['address'],
                'landmark'            => $data['landmark'],
                'lat'                 => $data['lat'] ?? null,
                'lng'                 => $data['lng'] ?? null,

                'description'         => $data['description'] ?? null,
                'pieces_count'        => (int) ($data['pieces_count'] ?? 1),
                'weight_grams'        => $weight,
                'is_fragile'          => (bool) ($data['is_fragile'] ?? false),
                'allow_open'          => (bool) ($data['allow_open'] ?? false),
                'notes'               => $data['notes'] ?? null,

                'cod_amount'          => $cod,
                'delivery_fee'        => $deliveryFee,
                'return_fee'          => $quote['return_fee'],
                'extra_fee'           => $extraFee,
                'cod_fee'             => $codFee,
                'discount'            => $discount,
                'total_fees'          => $totalFees,
                'merchant_due'        => $merchantDue,
                'fees_paid_by'        => $feesPaidBy,
                'payment_type'        => $cod > 0 ? 'cod' : ($data['payment_type'] ?? 'prepaid'),

                'status'              => ShipmentStatus::Created,
                'status_changed_at'   => now(),
                'scheduled_at'        => $data['scheduled_at'] ?? null,
                'pickup_request_id'   => $data['pickup_request_id'] ?? null,

                'created_by_user_id'  => $actor?->id,
                'source'              => $data['source'] ?? 'web',
            ]);

            /*
            | الاشتباه بالتكرار يُوسَم ولا يمنع: منعُ الإنشاء يوقف تاجراً
            | له طلبان حقيقيان لزبون واحد، والصمت يُمرّر شحنة تُحاسَب
            | مرّتين. فتُنشأ وتُعرَض على الموظّف ليحسم.
            */
            $shipment->forceFill(['dedupe_hash' => $this->duplicates->hashFor($shipment)])->save();

            if ($original = $this->duplicates->findOriginal($shipment)) {
                $shipment->forceFill(['duplicate_of_id' => $original->id])->save();
            }

            ShipmentEvent::create([
                'shipment_id' => $shipment->id,
                'from_status' => null,
                'to_status'   => ShipmentStatus::Created->value,
                'event_type'  => 'status_change',
                'actor_type'  => $actor ? 'user' : 'system',
                'actor_id'    => $actor?->id,
                'actor_name'  => $actor?->name,
                'note'        => 'إنشاء الشحنة',
                'meta'        => ['price_rule_id' => $quote['rule_id'], 'price_matched' => $quote['matched']],
                'ip'          => request()->ip(),
            ]);

            return $shipment;
        });
    }
}
