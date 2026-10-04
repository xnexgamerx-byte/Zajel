<?php

namespace App\Actions\Settlements;

use App\Models\AuditLog;
use App\Models\CourierSettlement;
use App\Models\MerchantRequest;
use App\Models\MerchantSettlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * حذف كشفٍ مسودّة — كشف مندوبٍ أو كشف تاجر.
 *
 * المسودّة لقطةٌ بلا أثر: الشحنات لا تُوسَم إلّا عند الإقفال، ولا يُقيَّد في الدفتر شيء.
 * فحذفها يُعيد شحناتها كما هي لتدخل الكشف التالي. وكان «أقفِله أو ألغِه» يُقال لمن يبني
 * كشفاً جديداً، ولا طريق في الشاشات إلى الإلغاء. أمّا المُقفَل فلا يُحذف: تصحيحه حركةٌ في
 * الدفتر، كما كُتب تحت زرّ إقفاله.
 */
class DeleteDraftSettlement
{
    public function handle(CourierSettlement|MerchantSettlement $settlement, User $actor): void
    {
        DB::transaction(function () use ($settlement, $actor) {
            // الحال بعد القفل: إقفالٌ سبق الحذف بلحظةٍ لا يُمحى كشفه
            $draft = $settlement->newQuery()->lockForUpdate()->find($settlement->id);

            if (! $draft) {
                throw ValidationException::withMessages(['settlement' => "حُذف كشف {$settlement->code} من قبل."]);
            }

            if ($draft->status !== 'draft') {
                throw ValidationException::withMessages([
                    'settlement' => "كشف {$draft->code} مُقفَل فلا يُحذف — تصحيحه حركةٌ في الدفتر.",
                ]);
            }

            if ($draft instanceof MerchantSettlement) {
                $this->reopenPaymentRequest($draft);
            }

            AuditLog::create([
                'user_id'        => $actor->id,
                'user_name'      => $actor->name,
                'action'         => 'settlement_draft_deleted',
                'auditable_type' => $draft::class,
                'auditable_id'   => $draft->id,
                'old_values'     => [
                    'code'            => $draft->code,
                    'party'           => $draft instanceof MerchantSettlement
                        ? $draft->merchant?->business_name
                        : $draft->courier?->name,
                    'shipments_count' => (int) $draft->shipments_count,
                    'net_amount'      => (int) $draft->net_amount,
                ],
                'ip'             => request()->ip(),
            ]);

            $draft->lines()->delete();
            $draft->delete();
        });
    }

    /**
     * «حاسبوني» الذي أُجيب بهذا الكشف يعود ينتظر الكشف التالي — إلّا إن فتح التاجر
     * طلباً غيره بعده، فذاك يُجاب، وهذا يُلغى لا يبقى طلبين.
     */
    protected function reopenPaymentRequest(MerchantSettlement $draft): void
    {
        $answered = MerchantRequest::where('merchant_settlement_id', $draft->id)->get();

        foreach ($answered as $request) {
            $another = MerchantRequest::where('merchant_id', $request->merchant_id)
                ->ofType($request->type)->open()->whereKeyNot($request->id)->exists();

            $request->forceFill($another
                ? ['status' => 'cancelled', 'cancelled_at' => now(), 'merchant_settlement_id' => null]
                : ['status' => 'open', 'handled_at' => null, 'handled_by_user_id' => null, 'merchant_settlement_id' => null],
            )->save();
        }
    }
}
