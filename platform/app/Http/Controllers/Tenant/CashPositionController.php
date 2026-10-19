<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Finance\CashPosition;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** «الموقف النقدي» (docs/plan/68 §١١): فئات النقد منفصلة، وتوقّع أربعة عشر يوماً بنطاقه وافتراضاته */
class CashPositionController extends Controller
{
    public function __invoke(Request $request): View
    {
        $position = new CashPosition($request->user());

        return view('tenant.profits.cash', [
            'c'        => $position->categories(),
            'forecast' => $position->forecast(),
        ]);
    }
}
