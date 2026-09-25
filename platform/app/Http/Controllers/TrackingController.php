<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Support\Tracking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * تتبّع الشحنة للزبون، بلا حساب، على نطاق الشركة.
 *
 * طريقان: رمز QR على الوصل يحمل رابطاً ببصمة لا تُحزَر (Tracking::token)،
 * أو رقم الوصل مع آخر أربعة أرقام من هاتف المستلم. والجواب واحدٌ حين لا
 * يُعثَر على الشحنة وحين تخطئ الأرقام: لا يعرف المخمِّن أيّ الوصولات موجود.
 */
class TrackingController extends Controller
{
    /** أحداثٌ يراها الزبون: تغيّرات الحالة وحدها، لا الأكياس ولا المال ولا الملاحظات الداخلية. */
    private const PUBLIC_EVENTS = ['status_change', 'forced_status'];

    public function form(Request $request): View|RedirectResponse
    {
        $number = trim((string) $request->query('number'));
        $digits = preg_replace('/\D+/', '', \App\Support\Phone::latinDigits((string) $request->query('phone')));

        if ($number === '' && $digits === '') {
            return view('track.form');
        }

        $shipment = strlen($digits) === 4 && $number !== ''
            ? Shipment::query()
                ->where(fn ($q) => $q->where('number', $number)->orWhere('barcode', $number))
                ->get()
                ->first(fn (Shipment $s) => in_array($digits, [
                    substr((string) $s->recipient_phone, -4),
                    substr((string) $s->recipient_phone_alt, -4),
                ], true))
            : null;

        if (! $shipment) {
            return back()->withInput()->withErrors([
                'number' => 'لم نجد شحنةً بهذا الرقم وهذه الأرقام. راجع رقم الوصل وآخر أربعة أرقام من هاتفك.',
            ]);
        }

        return redirect()->to(Tracking::url($shipment));
    }

    public function show(string $number, string $token): View
    {
        $shipment = Shipment::query()
            ->where('number', $number)
            ->with(['governorate:id,name_ar', 'city:id,name_ar', 'merchant:id,business_name'])
            ->first();

        abort_unless($shipment && Tracking::verify($shipment, $token), 404, 'رابط التتبّع غير صحيح.');

        $events = $shipment->events()
            ->whereIn('event_type', self::PUBLIC_EVENTS)
            ->with('failureReason:id,name_ar')
            ->orderByDesc('id')
            ->get(['id', 'shipment_id', 'to_status', 'failure_reason_id', 'created_at']);

        return view('track.show', [
            'shipment'  => $shipment,
            'events'    => $events,
            // المبلغ يخصّ من ينتظر شحنته: لا معنى له بعد التسليم أو الإرجاع أو الإلغاء
            'awaiting'  => $shipment->status->isOpen() && $shipment->status !== ShipmentStatus::Returning,
        ]);
    }
}
