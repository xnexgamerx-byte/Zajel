<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Shipment;
use App\Services\Shipments\ShipmentFilters;
use App\Support\StreamingXlsx;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * تصدير قائمة الشحنات كما تُرى — بفلاترها — إلى Excel، أو إلى صفحةٍ تُطبع
 * وتُحفظ PDF، كما في «EXCEL» و«PDF» أعلى كل قائمة في المعتاد.
 *
 * الملف يحمل أرقام الزبائن كاملة، وهي في الشاشة مخفيّة تُكشف واحداً واحداً:
 * فالتصدير صلاحيةٌ بعينها (shipments.export)، وكل تصديرٍ يُسجَّل بمن صدّره
 * وفلاتره وعدد صفوفه. نسخ قاعدة الزبائن يبقى ممكناً لمن يملكه — ومرئيّاً.
 */
class ShipmentExportController extends Controller
{
    /** ما يُعقل في ملفٍّ واحد؛ وما زاد يُضيَّق بالفلاتر. */
    public const EXCEL_LIMIT = 50_000;

    /** وما يُعقل في صفحةٍ تُطبع. */
    public const PRINT_LIMIT = 2_000;

    public function excel(Request $request): BinaryFileResponse
    {
        $query = $this->query($request);
        $total = (clone $query)->count();

        $sheet = new StreamingXlsx([
            ['رقم الوصل', 16], ['المرحلة', 16], ['تاريخ دخول المرحلة', 18], ['تاريخ الإنشاء', 18],
            ['التاجر', 24], ['رقم طلب التاجر', 16], ['المستلم', 20], ['هاتف المستلم', 15], ['هاتف بديل', 15],
            ['المحافظة', 12], ['المنطقة', 20], ['العنوان', 36], ['أقرب نقطة دالّة', 28],
            ['المبلغ', 12, 'number'], ['المحصَّل', 12, 'number'], ['الأجرة', 11, 'number'], ['مستحقّ التاجر', 13, 'number'],
            ['القطع', 7, 'number'], ['مندوب التوصيل', 18], ['مندوب الاستلام', 18], ['الفرع', 14],
            ['سبب آخر محاولة', 22], ['تمّ التحاسب مع التاجر', 12], ['ملاحظات', 30],
        ], 'الشحنات');

        // بالمعرّف نزولاً دفعةً دفعة: الأحدث أوّلاً كما في الشاشة، والذاكرة ثابتة
        foreach ($query->lazyByIdDesc(1000, 'shipments.id', 'id') as $s) {
            if ($sheet->count() >= self::EXCEL_LIMIT) {
                break;
            }

            $sheet->add([
                $s->number, $s->status->label(), $s->status_changed_at?->format('Y-m-d H:i'), $s->created_at?->format('Y-m-d H:i'),
                $s->merchant?->business_name, $s->merchant_reference, $s->recipient_name, $s->recipient_phone, $s->recipient_phone_alt,
                $s->governorate?->name_ar, $s->city?->name_ar, $s->address, $s->landmark,
                (int) $s->cod_amount, (int) $s->collected_amount, (int) $s->total_fees, (int) $s->merchant_due,
                (int) $s->pieces_count, $s->deliveryCourier?->name, $s->pickupCourier?->name, $s->branch?->name,
                $s->lastFailureReason?->name_ar, $s->merchant_settled_at ? 'نعم' : 'لا', $s->notes,
            ]);
        }

        $path = tempnam(sys_get_temp_dir(), 'shipments-').'.xlsx';
        $sheet->save($path);

        $this->audit($request, 'excel', $sheet->count(), $total);

        // اسمٌ لاتينيّ: بعض المتصفّحات تُسقط الاسم العربيّ فيصل الملف بلا اسم
        return response()->download($path, 'shipments-'.now()->format('Y-m-d-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public function print(Request $request): View
    {
        $query = $this->query($request);
        $total = (clone $query)->count();
        $shipments = $query->latest('shipments.id')->limit(self::PRINT_LIMIT)->get();

        $this->audit($request, 'print', $shipments->count(), $total);

        return view('tenant.shipments.print', [
            'shipments' => $shipments,
            'total'     => $total,
            'limit'     => self::PRINT_LIMIT,
            'filters'   => ShipmentFilters::active($request),
        ]);
    }

    protected function query(Request $request): Builder
    {
        $query = Shipment::query()
            ->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar', 'branch:id,name',
                    'deliveryCourier:id,name', 'pickupCourier:id,name', 'lastFailureReason:id,name_ar'])
            ->visibleTo($request->user());

        return ShipmentFilters::apply($query, $request);
    }

    protected function audit(Request $request, string $format, int $rows, int $total): void
    {
        AuditLog::create([
            'user_id'    => $request->user()->id,
            'user_name'  => $request->user()->name,
            'action'     => 'shipments_exported',
            'new_values' => ['format' => $format, 'rows' => $rows, 'matching' => $total,
                             'filters' => ShipmentFilters::active($request)],
            'ip'         => $request->ip(),
        ]);
    }
}
