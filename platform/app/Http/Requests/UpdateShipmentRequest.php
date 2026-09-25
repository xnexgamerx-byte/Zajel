<?php

namespace App\Http\Requests;

/**
 * قواعد التعديل هي قواعد الإنشاء نفسها، بلا التاجر: الشحنة تبقى لصاحبها.
 * نقلُها لتاجرٍ آخر ليس تصحيحاً بل شحنةٌ أخرى — تُلغى هذه وتُنشأ تلك.
 */
class UpdateShipmentRequest extends StoreShipmentRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['merchant_id']);

        return $rules;
    }
}
