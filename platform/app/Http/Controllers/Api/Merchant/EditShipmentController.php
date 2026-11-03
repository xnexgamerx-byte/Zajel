<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Requests\MerchantShipmentUpdateRequest;
use App\Models\Shipment;
use App\Support\MerchantEdit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «تعديل الشحنة» في تطبيق التاجر (docs/plan/113): ما يملأ النموذج بقيمها الآن، ثم الحفظ — بقواعد MerchantEdit.
 */
class EditShipmentController extends Controller
{
    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        $this->own($request, $shipment);
        $shipment->loadMissing(['governorate:id,name_ar', 'city:id,name_ar', 'deliveryCourier:id,is_intermediary']);

        return response()->json([
            'edit' => MerchantEdit::describe($shipment),
            'values' => [
                'recipient_name' => $shipment->recipient_name === Shipment::UNNAMED_RECIPIENT ? '' : $shipment->recipient_name,
                'recipient_phone' => $shipment->recipient_phone,
                'recipient_phone_alt' => $shipment->recipient_phone_alt,
                'governorate_id' => $shipment->governorate_id,
                'governorate' => $shipment->governorate?->name_ar,
                'city_id' => $shipment->city_id,
                'city' => $shipment->city?->name_ar,
                'address' => $shipment->address,
                'landmark' => $shipment->landmark,
                'description' => $shipment->description,
                'pieces_count' => (int) $shipment->pieces_count,
                'type' => $shipment->type,
                'size' => $shipment->size,
                'notes' => $shipment->notes,
                'cod_amount' => (int) $shipment->cod_amount,
            ],
        ]);
    }

    public function update(MerchantShipmentUpdateRequest $request, Shipment $shipment): JsonResponse
    {
        $this->own($request, $shipment);
        $shipment->loadMissing('deliveryCourier:id,is_intermediary');

        if ($why = MerchantEdit::locked($shipment)) {
            return response()->json(['message' => $why], 422);
        }

        $shipment = MerchantEdit::apply($shipment, $request->validated(), $request->user());
        $shipment->load(['governorate:id,name_ar', 'city:id,name_ar']);

        return response()->json([
            'message' => 'حُفظ التعديل.',
            'shipment' => ShipmentController::row($shipment),
        ]);
    }

    /** ربط المسار يفلتر بالشركة لا بالتاجر */
    private function own(Request $request, Shipment $shipment): void
    {
        abort_unless((int) $shipment->merchant_id === (int) $request->attributes->get('merchant')->id, 404);
    }
}
