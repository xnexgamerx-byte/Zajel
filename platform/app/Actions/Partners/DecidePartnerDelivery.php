<?php

namespace App\Actions\Partners;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Models\PartnerReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * قرارنا على تقرير تسليم الشريك (docs/plan/64): الموافقة تسلّم الطرد فعلاً وتحسب ماله،
 * والرفض يتركه كما هو مع سببٍ مكتوب، ويقدر الشريك يعيد الإرسال بعده.
 */
class DecidePartnerDelivery
{
    public function __construct(protected ChangeShipmentStatus $change) {}

    public function approve(PartnerReport $report, User $actor, ?string $note = null): void
    {
        DB::transaction(function () use ($report, $actor, $note) {
            $locked = PartnerReport::query()->lockForUpdate()->findOrFail($report->id);
            $this->ensurePending($locked);

            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();

            if ($shipment->status !== ShipmentStatus::OutForDelivery) {
                throw ValidationException::withMessages(['status' => 'الطرد لم يعد «قيد التوصيل»، فلا يُوافَق على تسليمه.']);
            }

            // الشريك سلّم كاملاً: المحصّل هو المبلغ كما هو (التحصيل الجزئي يدخل بعدين مع التسوية)
            $this->change->handle($shipment, ShipmentStatus::Delivered, $actor, [
                'collected_amount' => $shipment->cod_amount,
                'note' => trim('وافقنا على تسليم '.$locked->key->partner->name.($note ? ' — '.$note : '')),
            ]);

            $shipment->forceFill(['partner_id' => null])->save();

            $locked->forceFill([
                'decided_at' => now(),
                'decision' => 'approved',
                'decided_by_user_id' => $actor->id,
                'decision_note' => $note,
            ])->save();
        });
    }

    public function reject(PartnerReport $report, User $actor, string $note): void
    {
        DB::transaction(function () use ($report, $actor, $note) {
            $locked = PartnerReport::query()->lockForUpdate()->findOrFail($report->id);
            $this->ensurePending($locked);

            $locked->forceFill([
                'decided_at' => now(),
                'decision' => 'rejected',
                'decided_by_user_id' => $actor->id,
                'decision_note' => $note,
            ])->save();
        });
    }

    private function ensurePending(PartnerReport $report): void
    {
        if ($report->decided_at !== null) {
            throw ValidationException::withMessages(['report' => 'هذا التقرير قُرِّر من قبل.']);
        }
    }
}
