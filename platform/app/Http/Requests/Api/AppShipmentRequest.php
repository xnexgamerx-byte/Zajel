<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\PortalShipmentRequest;
use App\Support\Phone;

/**
 * «طلب جديد» من تطبيق التاجر (docs/plan/52): قواعد نموذج البوابة نفسها — حقوله وحدها،
 * والتاجر من حسابه، والأجور من التسعيرة. والهاتف يُقبل كما يكتبه الهاتف: بأرقامٍ عربية
 * أو بمسافات أو بـ ‎+964 — يُوحَّد قبل التحقّق كما يفعل الموقع في المتصفّح.
 */
class AppShipmentRequest extends PortalShipmentRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        foreach (['recipient_phone', 'recipient_phone_alt'] as $field) {
            if (filled($this->input($field))) {
                $this->merge([$field => Phone::normalise($this->input($field)) ?? Phone::latinDigits((string) $this->input($field))]);
            }
        }

        if (filled($this->input('cod_amount'))) {
            $this->merge(['cod_amount' => preg_replace('/\D+/', '', Phone::latinDigits((string) $this->input('cod_amount')))]);
        }

        $this->merge(['pieces_count' => $this->input('pieces_count') ?: 1]);
    }
}
