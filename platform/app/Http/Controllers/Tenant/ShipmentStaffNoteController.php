<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Support\StaffNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** «ملاحظة للموظّفين» من صفحة الشحنة (docs/plan/83) — كما تكتبها موظّفة الكول سنتر من التطبيق */
class ShipmentStaffNoteController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:'.StaffNote::MAX]], [], ['note' => 'الملاحظة']);

        StaffNote::write($shipment, $data['note'] ?? null, $request->user(), $request->ip());

        return back()->with('success', filled($shipment->staff_note) ? 'كُتبت الملاحظة للموظّفين.' : 'مُسحت الملاحظة.');
    }
}
