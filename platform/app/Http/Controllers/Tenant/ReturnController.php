<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Returns\HandOverReturns;
use App\Actions\Returns\ReceiveReturns;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Merchant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * الراجع في شاشتين لأنه في الواقع خطوتان.
 *
 * الطرد يعود من المندوب إلى المخزن، ثم يُسلَّم إلى التاجر. دمجهما يُنتج
 * أشهر خلاف في هذا المجال: طرد «راجع» في النظام والتاجر لم يره، أو
 * تاجر يُطالَب بأجرة راجع لطرد ما زال في حقيبة مندوب.
 */
class ReturnController extends Controller
{
    public function __construct(
        protected ReceiveReturns $receiver,
        protected HandOverReturns $handover,
    ) {}

    /** ١٤ — استلام الراجع من المندوب. */
    public function incoming(Request $request): View
    {
        $courierId = $request->integer('courier_id') ?: null;
        $shipments = $this->receiver->pending($courierId);

        return view('tenant.returns.incoming', [
            'shipments' => $shipments,
            'courierId' => $courierId,
            'couriers'  => Courier::delivering()->orderBy('name')->get(['id', 'name']),
            // عدّاد لكل مندوب: الموظّف يعرف مَن عنده راجع قبل أن يفتح قائمته
            'perCourier' => $this->receiver->pending()->groupBy('delivery_courier_id')->map->count(),
        ]);
    }

    public function receive(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shipment_ids'   => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer'],
            'note'           => ['nullable', 'string', 'max:255'],
        ], [], ['shipment_ids' => 'الشحنات']);

        $received = $this->receiver->handle($data['shipment_ids'], $request->user(), $data['note'] ?? null);

        if ($received->isEmpty()) {
            return back()->withErrors(['shipment_ids' => 'لم تُستلم أي شحنة — قد تكون مُستلمة سلفاً.']);
        }

        return back()->with('success', "استُلم الراجع من المندوب، عدد الشحنات {$received->count()}.");
    }

    /** ١٥ — تسليم الراجع للتاجر. */
    public function outgoing(Request $request): View
    {
        $merchantId = $request->integer('merchant_id') ?: null;
        $shipments = $this->handover->ready($merchantId);

        return view('tenant.returns.outgoing', [
            'shipments'   => $shipments,
            'merchantId'  => $merchantId,
            'merchants'   => Merchant::orderBy('business_name')->get(['id', 'business_name']),
            'perMerchant' => $this->handover->ready()->groupBy('merchant_id')->map->count(),
        ]);
    }

    public function deliver(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id'    => ['required', 'integer'],
            'shipment_ids'   => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer'],
            'note'           => ['nullable', 'string', 'max:255'],
        ], [], ['shipment_ids' => 'الشحنات', 'merchant_id' => 'التاجر']);

        $merchant = Merchant::find($data['merchant_id']);

        if (! $merchant) {
            return back()->withErrors(['merchant_id' => 'التاجر غير موجود.']);
        }

        $delivered = $this->handover->handle(
            $data['shipment_ids'], $merchant, $request->user(), $data['note'] ?? null,
        );

        if ($delivered->isEmpty()) {
            return back()->withErrors(['shipment_ids' => 'لم تُسلَّم أي شحنة — قد تكون مُسلَّمة سلفاً.']);
        }

        return back()->with(
            'success',
            "سُلّم الراجع إلى {$merchant->business_name}، عدد الشحنات {$delivered->count()}. "
            .'وقُيّدت أجرة الراجع على حسابه.',
        );
    }
}
