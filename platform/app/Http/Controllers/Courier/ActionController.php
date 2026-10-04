<?php

namespace App\Http\Controllers\Courier;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\FailureReason;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

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
    public function __construct(protected ChangeShipmentStatus $changeStatus) {}

    private const ALLOWED = [
        'delivered'           => ShipmentStatus::Delivered,
        'partially_delivered' => ShipmentStatus::PartiallyDelivered,
        'failed_attempt'      => ShipmentStatus::FailedAttempt,
        'postponed'           => ShipmentStatus::Postponed,
    ];

    public function __invoke(Request $request, Shipment $shipment): RedirectResponse
    {
        $courier = $request->attributes->get('courier');

        abort_unless($shipment->delivery_courier_id === $courier->id, 404);

        $data = $request->validate([
            'action'            => ['required', Rule::in(array_keys(self::ALLOWED))],
            // صفحةٌ قديمة فيها خانة المبلغ: يُقرأ ليُرفض إن غيّره، لا ليُقيَّد
            'collected_amount'  => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'failure_reason_id' => ['nullable', 'integer'],
            'note'              => ['nullable', 'string', 'max:500'],
            'delivery_code'     => ['nullable', 'string', 'max:10'],
            'lat'               => ['nullable', 'numeric', 'between:-90,90'],
            'lng'               => ['nullable', 'numeric', 'between:-180,180'],
        ], [], [
            'collected_amount'  => 'المبلغ المستلم',
            'failure_reason_id' => 'السبب',
            'note'              => 'الملاحظة',
        ]);

        $to = self::ALLOWED[$data['action']];

        if ($shipment->status !== ShipmentStatus::OutForDelivery) {
            return back()->withErrors(['action' => 'هذه الشحنة لم تعد بيدك.']);
        }

        // الواصل الجزئي بالمبلغ الذي اعتمده الكول سنتر، ولا غيره
        $partial = $to === ShipmentStatus::PartiallyDelivered
            ? ShipmentTicket::query()->where('shipment_id', $shipment->id)->where('courier_id', $courier->id)
                ->awaitingPartial()->latest('id')->first()
            : null;

        $errors = $this->guard($request, $shipment, $to, $data, $partial);

        if ($errors) {
            return back()->withErrors($errors)->withInput();
        }

        $this->changeStatus->handle($shipment, $to, $request->user(), array_filter([
            'actor_type'        => 'courier',
            'courier_id'        => $courier->id,
            'collected_amount'  => $partial?->approved_amount,
            'ticket_id'         => $partial?->id,
            'failure_reason_id' => $data['failure_reason_id'] ?? null,
            'note'              => $data['note'] ?? null,
            'lat'               => $data['lat'] ?? null,
            'lng'               => $data['lng'] ?? null,
        ], fn ($v) => $v !== null));

        return redirect()
            ->route('courier.tasks')
            ->with('success', "سُجِّلت الشحنة {$shipment->number}: {$to->label()}.");
    }

    /** @return array<string,string> */
    protected function guard(Request $request, Shipment $shipment, ShipmentStatus $to, array $data, ?ShipmentTicket $partial): array
    {
        // المبلغ ليس للمندوب: صفحةٌ قديمة كُتب فيها غيرُ مبلغ الشحنة لا تُسلَّم به
        if ($to === ShipmentStatus::Delivered && isset($data['collected_amount'])
            && (int) $data['collected_amount'] !== (int) $shipment->cod_amount) {
            return ['collected_amount' => 'المبلغ لا يتغيّر عند التسليم. إن قال الزبون مبلغاً آخر، اطلب تغيير المبلغ من الكول سنتر.'];
        }

        if ($to === ShipmentStatus::PartiallyDelivered && ! $partial) {
            return ['action' => 'الواصل الجزئي بموافقة الكول سنتر: اطلب تغيير المبلغ وانتظر اعتماده.'];
        }

        if (in_array($to, [ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed], true)) {
            $reason = isset($data['failure_reason_id'])
                ? FailureReason::availableFor($request->user()->company_id)
                    ->whereKey($data['failure_reason_id'])->first()
                : null;

            if (! $reason) {
                return ['failure_reason_id' => 'اختر السبب — بلا سبب لا يعرف التاجر لماذا لم تُسلَّم.'];
            }

            if ($reason->requires_note && ! trim((string) ($data['note'] ?? ''))) {
                return ['note' => "السبب «{$reason->name_ar}» يحتاج توضيحاً."];
            }
        }

        // كود التسليم: من الزبون عند الباب — خمس محاولاتٍ في الساعة لكل شحنة، فلا يُخمَّن
        if ($shipment->delivery_code && in_array($to, [ShipmentStatus::Delivered, ShipmentStatus::PartiallyDelivered], true)) {
            $key = 'delivery-code:'.$shipment->id;

            if (RateLimiter::tooManyAttempts($key, 5)) {
                return ['delivery_code' => 'محاولاتٌ كثيرة بكودٍ خاطئ. اتّصل بالشركة.'];
            }

            $typed = Phone::latinDigits(trim((string) ($data['delivery_code'] ?? '')));

            if (! hash_equals($shipment->delivery_code, $typed)) {
                RateLimiter::hit($key, 3600);

                return ['delivery_code' => $typed === ''
                    ? 'هذه الشحنة تُسلَّم بكود: اطلبه من الزبون.'
                    : 'كود التسليم غير صحيح.'];
            }

            RateLimiter::clear($key);
        }

        return [];
    }
}
