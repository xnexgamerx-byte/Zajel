<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «بدأت المتابعة» (docs/plan/53): موظّفٌ يتابع شحنةً لم تُسلَّم — اسمه يراه الجميع، وملاحظته له وحده.
 * وتعدّ للمحاولة الحاليّة وحدها: ما بدأ قبل آخر تعثّرٍ قديم (current()).
 */
class ShipmentFollowUp extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** للمحاولة التي تنتظر الآن: بدأت بعد أن تعثّرت الشحنة آخر مرّة */
    public function isCurrent(Shipment $shipment): bool
    {
        return $shipment->status_changed_at === null || $this->started_at->greaterThanOrEqualTo($shipment->status_changed_at);
    }

    /** شرط SQL: متابعةٌ حاليّة لهذه الشحنة (لفلاتر القائمة) */
    public const CURRENT_SQL = 'shipment_follow_ups.shipment_id = shipments.id and shipment_follow_ups.started_at >= shipments.status_changed_at';
}
