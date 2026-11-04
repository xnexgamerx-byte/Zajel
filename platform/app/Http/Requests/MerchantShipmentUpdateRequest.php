<?php

namespace App\Http\Requests;

use App\Http\Requests\Api\AppShipmentRequest;
use App\Models\Shipment;
use App\Support\MerchantEdit;

/**
 * تعديل التاجر لشحنته (docs/plan/113): قواعد نموذج إنشائه نفسها، من البوابة والتطبيق — والهاتف يُوحَّد كما
 * في التطبيق. وبعد الاستلام لا تعديل (docs/plan/118).
 */
class MerchantShipmentUpdateRequest extends AppShipmentRequest
{
    /** شحنته وحده: ربط المسار يفلتر بالشركة لا بالتاجر، فشحنة تاجرٍ آخر كأنها غير موجودة */
    public function authorize(): bool
    {
        $shipment = $this->route('shipment');

        abort_unless($shipment instanceof Shipment && $this->user()?->merchant_id
            && (int) $shipment->merchant_id === (int) $this->user()->merchant_id, 404);

        return true;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['waybill'], $rules['merchant_id']);

        $shipment = $this->route('shipment');

        // مقفلةٌ بعد الاستلام (docs/plan/118): لا قواعد، فيقول المتحكّم لماذا لا تُعدَّل بدل أخطاء الحقول
        if ($shipment instanceof Shipment && MerchantEdit::mode($shipment) === null) {
            return [];
        }

        return $rules;
    }
}
