<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\UpdateShipment;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * «حركات الطلب» (docs/plan/53): من لمس هذه الشحنة، ومتى، وماذا غيّر — كلّ تعديلٍ بحقوله
 * (من كذا إلى كذا)، وكلّ تغيير حالةٍ أو إجبارٍ أو مالٍ أو مسحٍ باسم من فعله ومن أيّ جهاز.
 * سجلّ الشحنة يحكي رحلتها؛ وهذه تحكي أيدي الموظّفين عليها.
 */
class ShipmentActivityController extends Controller
{
    /** أسماء الحقول في السطر: حقول التعديل، وأجرة المندوب من «تعديل الأجور» */
    private const FIELDS = UpdateShipment::FIELDS + ['courier_commission' => 'أجرة المندوب'];

    private const MONEY = ['cod_amount', 'delivery_fee', 'extra_fee', 'discount', 'cod_fee', 'return_fee',
        'total_fees', 'merchant_due', 'courier_commission', 'collected_amount'];

    public function __invoke(Request $request, Shipment $shipment): View
    {
        abort_unless(Shipment::visibleTo($request->user())->whereKey($shipment->id)->exists(), 404);

        $events = $shipment->events()->with('courier:id,name')->reorder()->latest('id')->get();

        return view('tenant.shipments.activity', [
            'shipment' => $shipment,
            'events'   => $events,
            'people'   => $events->where('actor_type', 'user')->groupBy('actor_name')
                ->map(fn (Collection $rows) => (object) [
                    'count' => $rows->count(),
                    'edits' => $rows->where('event_type', 'edited')->count(),
                    'last'  => $rows->first()->created_at,
                ])->sortByDesc('count'),
            'changes'  => fn (ShipmentEvent $event) => $this->changes($event),
        ]);
    }

    /** @return list<array{field: string, from: string, to: string}> */
    private function changes(ShipmentEvent $event): array
    {
        $out = [];

        foreach ((array) ($event->meta['changes'] ?? []) as $field => $change) {
            if (! is_array($change)) {
                continue;
            }

            $out[] = [
                'field' => self::FIELDS[$field] ?? $field,
                'from'  => $this->show($field, $change['from'] ?? null),
                'to'    => $this->show($field, $change['to'] ?? null),
            ];
        }

        return $out;
    }

    private function show(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match (true) {
            in_array($field, ['recipient_phone', 'recipient_phone_alt'], true) => '…'.substr((string) $value, -3),
            $field === 'governorate_id' => (string) Governorate::whereKey($value)->value('name_ar'),
            $field === 'city_id'        => (string) City::whereKey($value)->value('name_ar'),
            $field === 'fees_paid_by'   => $value === 'customer' ? 'الزبون' : 'التاجر',
            $field === 'type'           => Shipment::TYPES[$value] ?? (string) $value,
            $field === 'size'           => Shipment::SIZES[$value] ?? (string) $value,
            is_bool($value) || in_array($field, ['is_fragile', 'allow_open', 'fee_prepaid'], true) => $value ? 'نعم' : 'لا',
            in_array($field, self::MONEY, true) && is_numeric($value) => number_format((int) $value),
            default => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
