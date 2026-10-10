<?php

namespace App\Http\Controllers;

use App\Models\WaybillBook;
use App\Services\Import\ShipmentSheet;
use App\Support\WaybillPrint;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ما يفتحه تطبيق التاجر في متصفّح الهاتف (docs/plan/57): بلا دخولٍ ولا كعكة — الرابط نفسه موقَّعٌ
 * من الخادم لساعة، ويحمل رقم الدفتر، فلا يُفتح بغيره ولا بعد ساعته.
 */
class AppLinkController extends Controller
{
    public function waybills(Request $request, WaybillBook $book): View
    {
        return WaybillPrint::view($request, $book, '');
    }

    public function template(ShipmentSheet $sheet): BinaryFileResponse
    {
        return response()->download($sheet->template(), 'قالب-الشحنات.xlsx')->deleteFileAfterSend();
    }
}
