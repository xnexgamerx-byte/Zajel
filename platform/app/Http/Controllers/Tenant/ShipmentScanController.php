<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ReceiveAtHub;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «استلام وصولات في كل المراحل وإسنادها» كما في المعتاد: يُمسح الوصل فيدخل
 * جدولاً يُرى فيه صاحبه ومرحلته ومبلغه ووجهته، ثم فعلٌ واحد للجدول كلّه —
 * استلامٌ في المخزن، أو إسنادٌ لمندوب توصيل (بمسار الإسناد الجماعي نفسه).
 *
 * الجدول في المتصفّح حتى الحفظ: المسح لا يغيّر شيئاً، فمسحةٌ خاطئة تُحذف من
 * الجدول قبل أن تصير حركةً في سجلّ شحنة.
 */
class ShipmentScanController extends Controller
{
    public function index(Request $request): View
    {
        return view('tenant.shipments.scan', [
            'couriers' => Courier::delivering()->active()->visibleTo($request->user())->orderBy('name')
                ->with('zones.governorate:id,name_ar')->get(['id', 'name']),
            'hub'      => ReceiveAtHub::hubOf($request->user()),
        ]);
    }

    /** الوصل برقمه أو باركوده، لمن يراه وحده. */
    public function lookup(Request $request): JsonResponse
    {
        $number = trim((string) $request->query('number'));

        if ($number === '' || mb_strlen($number) > 40) {
            return response()->json(['error' => 'اكتب رقم الوصل أو امسحه.'], 422);
        }

        $shipment = Shipment::query()
            ->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar',
                    'branch:id,name', 'currentBag:id,code', 'deliveryCourier:id,name'])
            ->visibleTo($request->user())
            ->where(fn ($q) => $q->where('number', $number)->orWhere('barcode', $number))
            ->first();

        if (! $shipment) {
            return response()->json(['error' => "لا وصل برقم {$number}."], 404);
        }

        $phone = (string) $shipment->recipient_phone;

        return response()->json([
            'id'          => $shipment->id,
            'number'      => $shipment->number,
            'merchant'    => $shipment->merchant?->business_name,
            'status'      => $shipment->status->label(),
            'tone'        => $shipment->status->color(),
            'amount'      => (int) $shipment->cod_amount,
            'destination' => trim($shipment->governorate?->name_ar.' · '.($shipment->city?->name_ar ?? ''), ' ·'),
            'branch'      => $shipment->branch?->name,
            'courier'     => $shipment->deliveryCourier?->name,
            'bag'         => $shipment->currentBag?->code,
            // الرقم مخفيٌّ كما في القائمة: الجدول يُقرأ عند العدّاد ولا يُنسخ منه
            'phone'       => str_repeat('•', max(0, mb_strlen($phone) - 3)).mb_substr($phone, -3),
            'url'         => route('shipments.show', $shipment),
        ]);
    }

    public function receive(Request $request, ReceiveAtHub $action): RedirectResponse
    {
        $data = $request->validate([
            'shipment_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'shipment_ids.*' => ['integer'],
        ], [], ['shipment_ids' => 'الوصولات']);

        // ما لا يراه لا يُستلم باسمه ولو أُرسل رقمه
        $visible = Shipment::whereIn('id', $data['shipment_ids'])
            ->visibleTo($request->user())
            ->pluck('id')
            ->all();

        $result = $action->handle($visible, $request->user());

        $message = $result['received']
            ? 'استُلمت في المخزن '.\App\Support\Arabic::shipments(count($result['received'])).'.'
            : 'لم تُستلم أيّ شحنة.';

        if ($result['skipped']) {
            $message .= ' تُخطّيت '.count($result['skipped']).': '.collect($result['skipped'])
                ->take(6)->map(fn ($reason, $number) => "{$number} ({$reason})")->implode('، ')
                .(count($result['skipped']) > 6 ? '…' : '');
        }

        return redirect()->route('shipments.scan')->with('success', $message);
    }
}
