<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\ChangeStatusRequest;
use App\Models\Shipment;

/**
 * «حدّثي الحالة» من تطبيق الكول سنتر (docs/plan/80): قواعد صفحة الشحنة في الموقع نفسها، والشحنة
 * برقمها مما تراه الموظّفة — لا ربط نموذجٍ بلا مستخدمٍ في الواجهة.
 */
class CallCenterStatusRequest extends ChangeStatusRequest
{
    private ?Shipment $found = null;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('shipments.status');
    }

    public function shipment(): Shipment
    {
        return $this->found ??= Shipment::query()->visibleTo($this->user())
            ->findOrFail((int) $this->route('shipment'));
    }
}
