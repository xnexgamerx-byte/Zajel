<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Shipments\CreateShipment;
use App\Actions\Waybills\CreateFromWaybill;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PortalShipmentRequest;
use App\Models\City;
use App\Models\Conversation;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Models\WaybillBook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ShipmentController extends Controller
{
    /**
     * «شحناتي» ومعها «المؤرشفة»: ما رجع إلى التاجر وسُلّم له خرج من القائمة
     * الجارية إلى تبويبه — بتاريخ تسليمه وإيصال دفعته وسبب رجوعه.
     */
    public function index(Request $request): View
    {
        $merchant = $request->attributes->get('merchant');
        $archive = $request->query('tab') === 'archive';
        $returned = ShipmentStatus::Returned->value;
        // التاريخ في الأرشيف تاريخ تسليم الراجع، وفي الجارية تاريخ الإنشاء
        $dated = $archive ? 'returned_at' : 'created_at';

        $shipments = Shipment::query()
            ->with(['governorate:id,name_ar', 'city:id,name_ar',
                    ...($archive ? ['returnBatch:id,number', 'lastFailureReason:id,name_ar'] : [])])
            ->where('merchant_id', $merchant->id)
            ->search($request->query('q'))
            ->when($archive,
                fn ($q) => $q->where('status', $returned),
                fn ($q) => $q->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                    // الراجع المسلَّم في «المؤرشفة» — إلّا إن طُلب بحالته أو بُحث عنه
                    ->when(! $request->filled('status') && ! $request->filled('q'), fn ($q) => $q->where('status', '!=', $returned)))
            ->when($request->query('from'), fn ($q, $d) => $q->whereFromDate($dated, $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereUntilDate($dated, $d))
            ->when($archive, fn ($q) => $q->orderByDesc('returned_at'))
            ->latest('id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('portal.shipments.index', [
            'shipments' => $shipments,
            'statuses'  => ShipmentStatus::cases(),
            'archive'   => $archive,
            'archived'  => Shipment::where('merchant_id', $merchant->id)->where('status', $returned)->count(),
        ]);
    }

    public function create(Request $request): View
    {
        $merchant = $request->attributes->get('merchant');

        return view('portal.shipments.create', [
            // المحفوظة للتوّ: رقمها وطباعة وصلها، والنموذج تحتها فارغٌ للتالية
            'created'      => Shipment::where('merchant_id', $merchant->id)->find($request->session()->get('created')),
            'merchant'     => $merchant,
            'governorates' => Governorate::offered()->get(['id', 'name_ar']),
            'cities'       => City::where('is_active', true)->orderBy('name_ar')->get(['id', 'governorate_id', 'name_ar']),
        ]);
    }

    public function store(PortalShipmentRequest $request, CreateShipment $action, CreateFromWaybill $fromWaybill): RedirectResponse
    {
        $merchant = $request->attributes->get('merchant');
        $data = $request->validated() + ['source' => 'merchant_portal'];
        $code = WaybillBook::fromInput($data['waybill'] ?? null);
        unset($data['waybill']);

        if ($code === '') {
            $shipment = $action->handle($data, $request->user());
        } else {
            // وصلٌ مطبوع على الطرد: تحمل الشحنة رقمه، فيجدها به المسح في الشركة
            if ($problem = self::waybillProblem($code, $merchant->id)) {
                throw ValidationException::withMessages(['waybill' => $problem]);
            }

            try {
                $shipment = $fromWaybill->handle($code, $data, $request->user());
            } catch (ValidationException) {
                // سبقه إليه مسحٌ في اللحظة نفسها: رسالته قد تسمّي تاجراً غيره
                throw ValidationException::withMessages(['waybill' => 'استُعمل هذا الوصل لشحنةٍ أخرى.']);
            }
        }

        // الطلبات تُدخَل متتابعةً: نموذجٌ فارغ للتالية، وبطاقةٌ بالمحفوظة
        return redirect()->route('portal.shipments.create')->with('created', $shipment->id);
    }

    /**
     * ما يمنع التاجر من رقم وصلٍ مطبوع، أو null. وصولاته ووصولات المخزن تُقبل؛ ودفتر
     * تاجرٍ غيره كأنه غير موجود — فلا يُعرف منه اسم تاجرٍ ولا رقم شحنته.
     */
    public static function waybillProblem(string $code, int $merchantId): ?string
    {
        $book = WaybillBook::forCode($code);

        if (! $book || ($book->merchant_id && (int) $book->merchant_id !== $merchantId)) {
            return 'الرقم '.WaybillBook::normalise($code).' ليس من وصولاتك المطبوعة.';
        }

        $used = Shipment::withTrashed()->where('barcode', WaybillBook::codeFor(WaybillBook::serialOf($code)))->first();

        if ($used) {
            return (int) $used->merchant_id === $merchantId
                ? "استعملت هذا الوصل للشحنة {$used->number}."
                : 'استُعمل هذا الوصل لشحنةٍ أخرى.';
        }

        return null;
    }

    public function show(Request $request, Shipment $shipment): View
    {
        $merchant = $request->attributes->get('merchant');

        // ربط المسار بالنموذج يفلتر بالشركة لا بالتاجر
        abort_unless($shipment->merchant_id === $merchant->id, 404);

        $shipment->load([
            'governorate', 'city', 'lastFailureReason',
            'events' => fn ($q) => $q->whereIn('event_type', \App\Models\ShipmentEvent::MERCHANT_EVENTS)->orderBy('id'),
        ]);

        return view('portal.shipments.show', [
            'shipment'      => $shipment,
            'conversations' => Conversation::where('merchant_id', $merchant->id)->where('shipment_id', $shipment->id)
                ->orderByDesc('last_message_at')->get(['id', 'subject', 'status', 'last_message_at', 'merchant_unread']),
        ]);
    }
}
