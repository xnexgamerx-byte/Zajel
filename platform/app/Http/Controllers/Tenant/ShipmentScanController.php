<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ReceiveAtHub;
use App\Actions\Waybills\CreateFromWaybill;
use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Shipment;
use App\Support\FeatureGate;
use App\Support\ScanCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «استلام وصولات في كل المراحل وإسنادها» كما في المعتاد: يُمسح الوصل — باركوده
 * أو رمز QR الذي عليه — فيدخل جدولاً يُرى فيه صاحبه ومرحلته ومبلغه ووجهته، ثم
 * فعلٌ واحد للجدول كلّه — استلامٌ في المخزن، أو إسنادٌ لمندوب توصيل (بمسار
 * الإسناد الجماعي نفسه).
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

    /** الوصل بباركوده أو برمز QR الذي عليه (رابط التتبّع)، لمن يراه وحده. */
    public function lookup(Request $request): JsonResponse
    {
        $typed = (string) $request->query('number');
        $scan = ScanCode::read($typed);

        if (! $scan->usable() || mb_strlen($typed) > ScanCode::MAX_INPUT) {
            return response()->json(['error' => 'اكتب رقم الوصل أو امسحه.'], 422);
        }

        $shipment = $scan->find(Shipment::query()
            ->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar',
                    'branch:id,name', 'currentBag:id,code', 'deliveryCourier:id,name'])
            ->visibleTo($request->user()));

        if (! $shipment) {
            // وصلٌ مطبوعٌ مسبقاً لم تُدخَل شحنته بعد: يُدلّ على مكان إدخاله
            $error = ! $scan->isLink() && FeatureGate::enabled(Feature::Waybills) && CreateFromWaybill::problem($scan->code) === null
                ? "الوصل المطبوع {$scan->code} لم تُدخَل شحنته بعد — أدخِلها من «شحنة من وصلٍ مطبوع»."
                : $scan->notFound();

            return response()->json(['error' => $error], 404);
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
