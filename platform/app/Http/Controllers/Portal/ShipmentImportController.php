<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Shipments\ImportShipments;
use App\Http\Controllers\Concerns\ImportsShipments;
use App\Http\Controllers\Controller;
use App\Services\Import\ShipmentSheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * التاجر يرفع ملفه بنفسه — وهذا أهمّ من رفعه في لوحة الشركة:
 * الشركة تتوقّف عن إدخال بيانات موجودة أصلاً عند التاجر.
 */
class ShipmentImportController extends Controller
{
    use ImportsShipments;

    public function create(): View
    {
        return view('portal.shipments.import', [
            'columns'  => ShipmentSheet::COLUMNS,
            'required' => ShipmentSheet::REQUIRED,
        ]);
    }

    public function store(Request $request, ShipmentSheet $sheet, ImportShipments $import): View
    {
        $merchant = $request->attributes->get('merchant');

        return view('portal.shipments.import-preview',
            $this->preview($request, $this->storeUpload($request), $merchant, $sheet, $import) + [
                'merchant' => $merchant,
                'action'   => route('portal.shipments.import.confirm'),
                'back'     => route('portal.shipments.import'),
            ]);
    }

    public function confirm(Request $request, ShipmentSheet $sheet, ImportShipments $import): RedirectResponse
    {
        $merchant = $request->attributes->get('merchant');

        $data = $request->validate([
            'path'        => ['required', 'string'],
            'skip_errors' => ['nullable', 'boolean'],
        ]);

        $rows = $this->preview($request, $data['path'], $merchant, $sheet, $import)['rows'];
        $failed = $rows->filter(fn ($row) => ! empty($row['errors']));

        if ($failed->isNotEmpty() && ! $request->boolean('skip_errors')) {
            return back()->withErrors([
                'file' => "في الملف صفوف بأخطاء، عددها {$failed->count()}. صحّحه أو استورد الصفوف الصحيحة وحدها.",
            ]);
        }

        $created = $import->handle($rows, $merchant, $request->user(), 'merchant_portal');
        $this->forget($data['path']);

        $message = "أُنشئت شحناتك، عددها {$created->count()}. اطلب استلاماً متى جهّزت الطرود.";

        if ($failed->isNotEmpty()) {
            $message .= " وتُخطّيت صفوف بأخطاء، عددها {$failed->count()}.";
        }

        return redirect()->route('portal.shipments.index')->with('success', $message);
    }
}
