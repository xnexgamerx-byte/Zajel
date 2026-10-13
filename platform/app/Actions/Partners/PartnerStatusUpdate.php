<?php

namespace App\Actions\Partners;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Models\PartnerKey;
use App\Models\PartnerReport;
use App\Models\Shipment;
use App\Support\Partners\PartnerPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * الشريك يسجّل حالة طردٍ معه (docs/plan/64): ينتقل الطرد عندنا بسجلٍّ يذكر الشريك ومفتاحه،
 * و«سُلِّم» تقريرٌ ينتظر موافقتنا قبل أن يحسب مالياً.
 */
class PartnerStatusUpdate
{
    public function __construct(protected ChangeShipmentStatus $change) {}

    /**
     * @param  array{status: string, note?: ?string, lat?: ?float, lng?: ?float}  $data
     * @return array{shipment: Shipment, report: ?PartnerReport}
     */
    public function handle(PartnerKey $key, string $number, array $data): array
    {
        return DB::transaction(function () use ($key, $number, $data) {
            $shipment = Shipment::query()->where('number', $number)->lockForUpdate()->first();

            // الطرد غير موجود عند هذا الشريك: الجواب واحد، لا نكشف إن كان لغيره
            if ($shipment === null || $shipment->partner_id !== $key->partner_id) {
                throw new ModelNotFoundException;
            }

            $from = $shipment->status;
            $to = PartnerPolicy::parse($data['status']);

            if ($to === null || ! in_array($to, PartnerPolicy::next($from), true)) {
                throw ValidationException::withMessages([
                    'status' => 'لا ينتقل الطرد من «'.$from->label().'» إلى «'.($to?->label() ?? $data['status']).'» من نظامكم.',
                ]);
            }

            $note = filled($data['note'] ?? null) ? trim((string) $data['note']) : null;

            if ($to === ShipmentStatus::Delivered) {
                $pending = PartnerReport::query()->where('shipment_id', $shipment->id)->pending()->exists();
                if ($pending) {
                    throw ValidationException::withMessages(['status' => 'عنده تقرير تسليمٍ بانتظار المراجعة.']);
                }

                $report = PartnerReport::create([
                    'company_id' => $shipment->company_id,
                    'shipment_id' => $shipment->id,
                    'partner_key_id' => $key->id,
                    'kind' => PartnerReport::DELIVERED,
                    'note' => $note,
                    'lat' => $data['lat'] ?? null,
                    'lng' => $data['lng'] ?? null,
                    'reported_at' => now(),
                ]);

                return ['shipment' => $shipment, 'report' => $report];
            }

            $shipment = $this->change->handle($shipment, $to, null, [
                'actor_type' => 'api',
                'actor_name' => $key->partner->name.' — '.$key->name,
                'note' => $note,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
            ]);

            return ['shipment' => $shipment, 'report' => null];
        });
    }
}
