<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Models\FailureReason;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use Illuminate\Support\Carbon;

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

        // موعد المؤجّلة (docs/plan/75): من اليوم إلى شهرٍ — ومنه تذكير المندوب في موعده
        if ($to === ShipmentStatus::Postponed && filled($data['until'] ?? null)) {
            $until = rescue(fn () => Carbon::parse($data['until']), null, false);

            if (! $until || $until->lt(today()) || $until->gt(today()->addDays(30)->endOfDay())) {
                return ['until' => 'الموعد من اليوم إلى شهرٍ قادم.'];
            }
        }

        // لا كود تسليم (docs/plan/76): المندوب يسلّم الطلب بلا إجراءٍ آخر عند الباب، ولو حمل وصلٌ قديم كوداً

        return [];
    }
}
