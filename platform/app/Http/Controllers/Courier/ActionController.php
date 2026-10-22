<?php

namespace App\Http\Controllers\Courier;

use App\Actions\Couriers\RecordCourierOutcome;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ما يستطيع المندوب فعله بشحنة بيده — ولا شيء غيره.
 *
 * أربعة انتقالات فقط، وكلّها من "مع المندوب". لا يستطيع إرجاع شحنة
 * ولا إلغاءها ولا تعديل مبلغها: هذه قرارات الشركة، والمندوب ينفّذ
 * ويسجّل ما جرى عند الباب.
 *
 * والمبلغ لا يُكتب هنا (docs/plan/30): «واصل» بمبلغ الشحنة كما هو، و«واصل جزئي»
 * بما اعتمده الكول سنتر في طلب تغيير المبلغ وحده. كان المندوب يكتب ما يشاء
 * ولا يصل الشركة شيء.
 */
class ActionController extends Controller
{
    public function __construct(protected RecordCourierOutcome $outcome) {}

    public function __invoke(Request $request, Shipment $shipment): RedirectResponse
    {
        $courier = $request->attributes->get('courier');

        abort_unless($shipment->delivery_courier_id === $courier->id, 404);

        $data = $request->validate([
            'action'            => ['required', Rule::in(array_keys(RecordCourierOutcome::ACTIONS))],
            // صفحةٌ قديمة فيها خانة المبلغ: يُقرأ ليُرفض إن غيّره، لا ليُقيَّد
            'collected_amount'  => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'failure_reason_id' => ['nullable', 'integer'],
            'note'              => ['nullable', 'string', 'max:500'],
            'delivery_code'     => ['nullable', 'string', 'max:10'],
            // الطرد ممسوحٌ في يده (docs/plan/72)
            'scan'              => ['nullable', 'string', 'max:300'],
            'lat'               => ['nullable', 'numeric', 'between:-90,90'],
            'lng'               => ['nullable', 'numeric', 'between:-180,180'],
        ], [], [
            'collected_amount'  => 'المبلغ المستلم',
            'failure_reason_id' => 'السبب',
            'note'              => 'الملاحظة',
        ]);

        // القواعد نفسها التي يمرّ بها تطبيق المندوب (docs/plan/66)
        try {
            $done = $this->outcome->handle($courier, $request->user(), $shipment, $data['action'], $data);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('courier.tasks')
            ->with('success', RecordCourierOutcome::message($done));
    }
}
