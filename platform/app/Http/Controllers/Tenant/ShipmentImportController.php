<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ImportShipments;
use App\Http\Controllers\Concerns\ImportsShipments;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\Import\ShipmentSheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShipmentImportController extends Controller
{
    use ImportsShipments;

    public function create(): View
    {
        return view('tenant.shipments.import', [
            'merchants' => Merchant::where('status', 'active')->orderBy('business_name')->get(['id', 'business_name']),
            'columns'   => ShipmentSheet::COLUMNS,
            'required'  => ShipmentSheet::REQUIRED,
        ]);
    }

    public function store(Request $request, ShipmentSheet $sheet, ImportShipments $import): View|RedirectResponse
    {
        $request->validate(['merchant_id' => ['required', 'integer']], [], ['merchant_id' => 'التاجر']);

        $merchant = Merchant::find($request->integer('merchant_id'));

        if (! $merchant) {
            return back()->withErrors(['merchant_id' => 'التاجر غير موجود.']);
        }

        $preview = $this->preview($request, $this->storeUpload($request), $merchant, $sheet, $import);

        return view('tenant.shipments.import-preview', $preview + [
            'merchant' => $merchant,
            'action'   => route('shipments.import.confirm'),
            'back'     => route('shipments.import'),
        ]);
    }

    public function confirm(Request $request, ShipmentSheet $sheet, ImportShipments $import): RedirectResponse
    {
        $data = $request->validate([
            'path'        => ['required', 'string'],
            'merchant_id' => ['required', 'integer'],
            'skip_errors' => ['nullable', 'boolean'],
        ]);

        $merchant = Merchant::findOrFail($data['merchant_id']);
        $preview = $this->preview($request, $data['path'], $merchant, $sheet, $import);
        $rows = $preview['rows'];

        $failed = $rows->filter(fn ($row) => ! empty($row['errors']));

        if ($failed->isNotEmpty() && ! $request->boolean('skip_errors')) {
            return back()->withErrors([
                'file' => "في الملف صفوف بأخطاء، عددها {$failed->count()}. صحّحه أو استورد الصفوف الصحيحة وحدها.",
            ]);
        }

        $created = $import->handle($rows, $merchant, $request->user());
        $this->forget($data['path']);

        $message = "أُنشئت الشحنات لـ {$merchant->business_name}، عددها {$created->count()}.";

        if ($failed->isNotEmpty()) {
            $message .= " وتُخطّيت صفوف بأخطاء، عددها {$failed->count()}.";
        }

        return redirect()->route('shipments.index')->with('success', $message);
    }
}
