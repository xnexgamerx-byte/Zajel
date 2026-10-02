<?php

namespace App\Support;

use App\Models\Shipment;
use App\Models\WaybillBook;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * صفحة طباعة دفترٍ من الوصولات المطبوعة مسبقاً — للتاجر من بوابته وللموظّف من
 * «دفاتر الوصولات المطبوعة» — بمقاس ملصقات الطابعة (WaybillBook::PRINT_SIZES).
 * وما استُعمل من الدفتر لا يُطبع ثانيةً، فلا يحمل طردان رقماً واحداً.
 */
final class WaybillPrint
{
    public static function view(Request $request, WaybillBook $book, string $back): View
    {
        $size = (string) $request->query('size');
        $size = array_key_exists($size, WaybillBook::PRINT_SIZES) ? $size : array_key_first(WaybillBook::PRINT_SIZES);

        // ما استُعمل لا يُطبع ثانيةً: إعادة طباعة دفترٍ تُخرج ما بقي منه وحده
        $codes = $book->codes();
        $used = Shipment::withTrashed()->whereIn('barcode', $codes)->pluck('barcode')->all();
        $codes = array_values(array_diff($codes, $used));

        $book->forceFill(['printed_at' => now()])->save();

        return view('labels.waybills', [
            'book'         => $book,
            'codes'        => $codes,
            'usedCount'    => count($used),
            'size'         => $size,
            'merchantName' => $book->merchant?->business_name,
            'terms'        => WaybillBook::terms(app(TenantContext::class)->company()),
            'back'         => $back,
        ]);
    }
}
