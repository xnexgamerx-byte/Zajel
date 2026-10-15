<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Models\FailureReason;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use App\Support\Phone;
use Illuminate\Support\Facades\RateLimiter;

/**
 * شروط ما يسجّله المندوب عند الباب — من شاشته أو من نظام الشركة الوسيطة (docs/plan/64).
 * مصدرٌ واحد، فلا يفترق المسار الآلي عن مسار المندوب في كودٍ ولا سببٍ ولا مبلغ.
 */
class CourierOutcomeRules
{
    /** @return array<string,string> خطأٌ لكل حقل، أو فارغة إن جاز التسجيل */
    public static function errors(Shipment $shipment, ShipmentStatus $to, array $data, ?ShipmentTicket $partial): array
    {
        // المبلغ ليس للمندوب: صفحةٌ قديمة كُتب فيها غيرُ مبلغ الشحنة لا تُسلَّم به
        if ($to === ShipmentStatus::Delivered && isset($data['collected_amount'])
            && (int) $data['collected_amount'] !== (int) $shipment->cod_amount) {
            return ['collected_amount' => 'المبلغ لا يتغيّر عند التسليم. إن قال الزبون مبلغاً آخر، اطلب تغيير المبلغ من الكول سنتر.'];
        }

        if ($to === ShipmentStatus::PartiallyDelivered && ! $partial) {
            return ['action' => 'الواصل الجزئي بموافقة الكول سنتر: اطلب تغيير المبلغ وانتظر اعتماده.'];
        }

        if (in_array($to, [ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed], true)) {
            $reason = isset($data['failure_reason_id'])
                ? FailureReason::availableFor($shipment->company_id)
                    ->whereKey($data['failure_reason_id'])->first()
                : null;

            if (! $reason) {
                return ['failure_reason_id' => 'اختر السبب — بلا سبب لا يعرف التاجر لماذا لم تُسلَّم.'];
            }

            if ($reason->requires_note && ! trim((string) ($data['note'] ?? ''))) {
                return ['note' => "السبب «{$reason->name_ar}» يحتاج توضيحاً."];
            }
        }

        // كود التسليم: من الزبون عند الباب — خمس محاولاتٍ في الساعة لكل شحنة، فلا يُخمَّن
        if ($shipment->delivery_code && in_array($to, [ShipmentStatus::Delivered, ShipmentStatus::PartiallyDelivered], true)) {
            $key = 'delivery-code:'.$shipment->id;

            if (RateLimiter::tooManyAttempts($key, 5)) {
                return ['delivery_code' => 'محاولاتٌ كثيرة بكودٍ خاطئ. اتّصل بالشركة.'];
            }

            $typed = Phone::latinDigits(trim((string) ($data['delivery_code'] ?? '')));

            if (! hash_equals($shipment->delivery_code, $typed)) {
                RateLimiter::hit($key, 3600);

                return ['delivery_code' => $typed === ''
                    ? 'هذه الشحنة تُسلَّم بكود: اطلبه من الزبون.'
                    : 'كود التسليم غير صحيح.'];
            }

            RateLimiter::clear($key);
        }

        return [];
    }
}
