<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Actions\Receipts\ConfirmReceipt;
use App\Http\Controllers\Controller;
use App\Models\MerchantSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «استلام بالباركود» في تطبيق التاجر (docs/plan/69): كاميرا التطبيق تمسح الورقة التي وصلت — وصل
 * الحساب أو إيصال الراجع — فيُؤكَّد استلام ما عليها. لا يُختار كشفٌ: الرمز يدلّ على ورقته.
 */
class ReceiveController extends Controller
{
    public function __invoke(Request $request, ConfirmReceipt $receipts): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:300']], [], ['code' => 'رمز الورقة']);

        $doc = $receipts->find($request->attributes->get('merchant'), $data['code']);

        if (! $doc) {
            return response()->json(['message' => ConfirmReceipt::NOT_YOURS], 422);
        }

        [$confirmed, $message] = $receipts->confirm($doc, $request->user(), 'التاجر بمسح الإيصال من التطبيق');

        return response()->json([
            'message' => $message,
            'type' => $doc instanceof MerchantSettlement ? 'statement' : 'returns',
            'id' => $doc->id,
        ], $confirmed ? 200 : 422);
    }
}
