<?php

namespace App\Actions\Returns;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;

/**
 * طردٌ مُسح في شاشةٍ من شاشات الراجع: هل هو من قائمتها؟ وإن لم يكن، فلماذا؟
 *
 * الشروط هي شروط القوائم نفسها (ReceiveReturns::pending وHandOverReturns::ready)،
 * فما يُقبل بالمسح هو ما تعرضه القائمة. والفرق أن الطرد في يد الموظّف: «ليس في
 * القائمة» لا تكفيه، يحتاج أن يعرف أين يذهب بهذا الطرد — مستلَمٌ سلفاً، أو لم
 * يُقرَّر إرجاعه بعد، أو راجعُ تاجرٍ آخر.
 */
class ScannedReturn
{
    public const STAGES = ['incoming', 'handover', 'pickup'];

    /** @return string|null سبب الرفض، أو null إن كان من قائمة هذه الخطوة */
    public function problem(Shipment $shipment, string $stage, ?int $merchantId = null, ?int $pickupCourierId = null): ?string
    {
        $status = $shipment->status;

        if ($status !== ShipmentStatus::Returning) {
            return $this->notReturning($shipment);
        }

        if ($stage === 'incoming') {
            return $shipment->return_received_at === null
                ? null
                : 'استُلم من المندوب سلفاً'.($shipment->current_bag_id
                    ? '، وهو في الكيس '.$shipment->currentBag?->code.'.'
                    : ' ('.$shipment->return_received_at->diffForHumans().') — يُسلَّم لتاجره من «تسليم الراجع للتاجر».');
        }

        if ($shipment->return_received_at === null) {
            return 'لم يُستلم من المندوب بعد — يُستلم أوّلاً من «استلام الراجع من المندوب».';
        }

        if ($shipment->current_bag_id) {
            return 'في الكيس '.$shipment->currentBag?->code.' ولم يُفتح بعد.';
        }

        $away = Shipment::query()->whereKey($shipment->id)->awayFromHomeBranch()->exists();

        if ($away) {
            return 'على رفّ فرعٍ غير فرع تاجره — يُفرَز إليه أوّلاً من «فرز الراجع للفروع».';
        }

        if ($stage === 'handover' && $merchantId && (int) $shipment->merchant_id !== $merchantId) {
            return 'راجعُ «'.$shipment->merchant?->business_name.'» لا هذا التاجر.';
        }

        if ($stage === 'pickup' && $pickupCourierId && (int) $shipment->merchant?->pickup_courier_id !== $pickupCourierId) {
            return 'تاجره «'.$shipment->merchant?->business_name.'» ليس من تجّار هذا المندوب.';
        }

        return null;
    }

    /** ليس راجعاً: أين هو إذن، وما الذي يُفعل به */
    protected function notReturning(Shipment $shipment): string
    {
        $status = $shipment->status;

        return match ($status) {
            ShipmentStatus::Returned => 'سُلِّم لتاجره سلفاً'
                .($shipment->returnBatch ? ' بإيصال '.$shipment->returnBatch->number : '')
                .($shipment->returned_at ? ' ('.$shipment->returned_at->format('Y-m-d').')' : '').'.',
            ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed => "لم يُقرَّر إرجاعه بعد — حالته «{$status->label()}»"
                .($shipment->lastFailureReason ? ' ('.$shipment->lastFailureReason->name_ar.')' : '')
                .'. يُقرَّر «راجع للتاجر» من «شحنات لم تُسلَّم (للمعالجة)»، أو يدخل المخزن من «استلام وتوزيع بالمسح».',
            ShipmentStatus::OutForDelivery => 'ما زال «قيد التوصيل»'
                .($shipment->deliveryCourier ? ' مع '.$shipment->deliveryCourier->name : '')
                .' — يُسجَّل أوّلاً أنه لم يُسلَّم وسببه.',
            ShipmentStatus::PartiallyDelivered => 'واصل جزئي — باقيه يُحوَّل إلى «راجع» من صفحة الشحنة أوّلاً.',
            default => "حالته «{$status->label()}» — ليس راجعاً.",
        };
    }
}
