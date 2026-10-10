<?php

namespace App\Actions\Merchants;

use App\Enums\ShipmentStatus;
use App\Models\Merchant;
use App\Models\MerchantRequest;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SequenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * طلب التاجر من بوابته: «حاسبوني» أو «سلّموني راجعي».
 *
 * طلبٌ مفتوح واحد من كل نوع: الطلب الثاني قبل معالجة الأول لا يُسرّع شيئاً
 * ويُربك من يعالجها. ولا طلب لما لا وجود له — لا دفع بلا رصيد، ولا كشف راجع
 * بلا راجع.
 */
class SubmitMerchantRequest
{
    public function __construct(protected SequenceGenerator $sequences) {}

    public function handle(Merchant $merchant, string $type, array $data, ?User $actor = null): MerchantRequest
    {
        return DB::transaction(function () use ($merchant, $type, $data, $actor) {
            // القفل على التاجر: طلبان متزامنان لا يمرّان معاً
            $merchant = Merchant::whereKey($merchant->id)->lockForUpdate()->firstOrFail();

            $open = MerchantRequest::where('merchant_id', $merchant->id)->ofType($type)->open()->first();

            if ($open) {
                throw ValidationException::withMessages([
                    'type' => "لك طلبٌ مفتوح برقم {$open->number} — يُعالَج قريباً، ولا حاجة لطلبٍ ثانٍ.",
                ]);
            }

            // يُطلب المتاحُ للسحب وحده: ما نقده ما زال مع المندوب «قيد المطابقة» (docs/plan/49)
            $balance = \App\Support\MerchantBalance::of($merchant);

            if ($type === 'payment' && $balance->available() <= 0) {
                throw ValidationException::withMessages(['type' => $balance->pending > 0
                    ? 'لا مبلغ متاحاً للسحب الآن: '.number_format($balance->pending).' د.ع قيد المطابقة '.$balance->reason()
                    : 'لا رصيد لك عندنا الآن لتطلب دفعه.']);
            }

            if ($type === 'returns' && ! Shipment::where('merchant_id', $merchant->id)
                ->where('status', ShipmentStatus::Returning->value)->exists()) {
                throw ValidationException::withMessages(['type' => 'لا راجع لك عندنا الآن.']);
            }

            $method = $type === 'payment' ? ($data['payout_method'] ?? $merchant->payout_method) : null;

            return MerchantRequest::create([
                'merchant_id'        => $merchant->id,
                'type'               => $type,
                // REQ-يوم-رقم: يُقرأ على الهاتف ويُعرف يومه من رقمه
                'number'             => 'REQ-'.now()->format('ymd').'-'.$this->sequences->next('merchant_request'),
                'payout_method'      => $method,
                // تفاصيل البطاقة أو المحفظة كما كتبها، ومبلغ رصيده ساعةَ طلب (docs/plan/44)
                'payout_details'     => \App\Support\PayoutMethods::needsDetails($method)
                    ? (trim((string) ($data['payout_details'] ?? '')) ?: $merchant->payout_account) : null,
                'amount'             => $type === 'payment' ? $balance->available() : null,
                // النقد بيد مندوب الاستلام أو من الشركة؛ وما سواه لا يحمله مندوب
                'via_pickup_courier' => $type === 'returns' || $method === 'cash'
                    ? (bool) ($data['via_pickup_courier'] ?? false) : false,
                'note'               => $data['note'] ?? null,
                'status'             => 'open',
                'created_by_user_id' => $actor?->id,
            ]);
        });
    }
}
