<?php

namespace App\Actions\Receipts;

use App\Actions\Returns\HandOverReturns;
use App\Enums\Feature;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Models\ReturnBatch;
use App\Models\User;
use App\Support\FeatureGate;
use App\Support\ReceiptCode;

/**
 * التاجر يؤكّد ما وصله بمسح ورقته (docs/plan/69): وصل الحساب الذي جاء مع ماله، أو إيصال
 * الراجع الذي جاء مع رواجعه. الرمز على الورقة وحدها، فلا يُؤكَّد ما لم يصل بيده.
 *
 * والمسح يُغني عن اختيار الكشف: رمز الورقة يدلّ على ورقته — وورقة تاجرٍ آخر «ليست من أوراقك».
 */
class ConfirmReceipt
{
    public function __construct(private HandOverReturns $returns) {}

    /** في هذه الشركة: لا يُؤكَّد استلامٌ بزرٍّ بلا الورقة */
    public static function required(): bool
    {
        return FeatureGate::enabled(Feature::ScanReceipts);
    }

    /** ورقة هذا التاجر التي مُسح رمزها، أو null */
    public function find(Merchant $merchant, ?string $scanned): MerchantSettlement|ReturnBatch|null
    {
        $code = ReceiptCode::normalize($scanned);

        if ($code === null) {
            return null;
        }

        return MerchantSettlement::where('merchant_id', $merchant->id)->where('receipt_code', $code)->first()
            ?? ReturnBatch::where('merchant_id', $merchant->id)->where('receipt_code', $code)->first();
    }

    /**
     * يؤكّد الاستلام مرّةً واحدة. الدفعة لا تُؤكَّد قبل دفعها.
     *
     * @return array{0: bool, 1: string} نجح؟ وما يُقال للتاجر
     */
    public function confirm(MerchantSettlement|ReturnBatch $doc, User $actor, string $by): array
    {
        if ($doc instanceof MerchantSettlement) {
            $confirmed = MerchantSettlement::whereKey($doc->id)->where('status', 'paid')->whereNull('merchant_confirmed_at')
                ->update(['merchant_confirmed_at' => now()]) === 1;

            return [$confirmed, $confirmed
                ? "أكّدت استلام دفعة الكشف {$doc->code}."
                : "الكشف {$doc->code} لم يُدفع بعد أو أكّدته سلفاً."];
        }

        $confirmed = $this->returns->confirmReceived($doc, $by, $actor, 'merchant');

        return [$confirmed, $confirmed
            ? "أكّدت استلام رواجع الإيصال {$doc->number}."
            : "الإيصال {$doc->number} مؤكَّدٌ سلفاً."];
    }

    /** ما يُقال حين يُطلب التأكيد بزرٍّ والشركة تطلب الورقة */
    public static function scanFirst(MerchantSettlement|ReturnBatch $doc): string
    {
        return $doc instanceof MerchantSettlement
            ? "امسح الباركود المطبوع على وصل الكشف {$doc->code} الذي وصلك مع المبلغ، أو اكتب رمزه — لا يُؤكَّد الاستلام بلا الورقة."
            : "امسح الباركود المطبوع على إيصال الراجع {$doc->number} الذي وصلك مع الرواجع، أو اكتب رمزه — لا يُؤكَّد الاستلام بلا الورقة.";
    }

    public const NOT_YOURS = 'هذا الرمز ليس لورقةٍ من أوراقك. امسح الباركود المطبوع على الوصل الذي وصلك، أو اكتب الرمز الذي تحته.';
}
