<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\PickupRequest;
use App\Services\SequenceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * «طلبات الاستلام» في تطبيق التاجر (docs/plan/56): قواعد البوابة نفسها
 * (PortalPickupRequestController) — طلبٌ مفتوحٌ واحد، وعنوانه وهاتفه من حسابه ما لم يكتب غيرهما.
 */
class PickupController extends Controller
{
    public const STATUSES = [
        'pending'     => 'بانتظار مندوب',
        'assigned'    => 'أُسند لمندوب',
        'in_progress' => 'في الطريق',
        'completed'   => 'تمّ الاستلام',
        'cancelled'   => 'ملغى',
    ];

    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        $page = PickupRequest::where('merchant_id', $merchant->id)
            ->with('courier:id,name,phone')->latest('id')->paginate(20);

        return response()->json([
            // ما يُكتب في الطلب الجديد إن تُرك فارغاً
            'address' => $merchant->address,
            'phone'   => $merchant->phone,
            'open'    => PickupRequest::where('merchant_id', $merchant->id)->whereIn('status', ['pending', 'assigned'])->exists(),
            'page'    => $page->currentPage(),
            'last'    => $page->lastPage(),
            'data'    => $page->getCollection()->map(fn (PickupRequest $p) => [
                'id'       => $p->id,
                'number'   => $p->number,
                'status'   => $p->status,
                'label'    => self::STATUSES[$p->status] ?? $p->status,
                'expected' => (int) $p->expected_count,
                'actual'   => $p->actual_count ? (int) $p->actual_count : null,
                'courier'  => $p->courier ? ['name' => $p->courier->name, 'phone' => $p->courier->phone] : null,
                'at'       => $p->requested_at?->toIso8601String(),
                'day'      => $p->scheduled_at?->toDateString(),
                'notes'    => $p->notes,
            ])->values(),
        ]);
    }

    public function store(Request $request, SequenceGenerator $sequences): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        $data = $request->validate([
            'expected_count' => ['required', 'integer', 'min:1', 'max:5000'],
            'scheduled_at'   => ['nullable', 'date', 'after_or_equal:today'],
            'address'        => ['nullable', 'string', 'max:255'],
            'contact_phone'  => ['nullable', 'string', 'regex:/^07[0-9]{9}$/'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ], [], ['expected_count' => 'عدد الطرود', 'scheduled_at' => 'الموعد', 'contact_phone' => 'هاتف التواصل']);

        if (PickupRequest::where('merchant_id', $merchant->id)->whereIn('status', ['pending', 'assigned'])->exists()) {
            throw ValidationException::withMessages([
                'expected_count' => 'عندك طلب استلام مفتوح. انتظر وصول المندوب، أو اتّصل بالشركة لتعديل عدده.',
            ]);
        }

        $pickup = PickupRequest::create([
            'merchant_id'        => $merchant->id,
            'branch_id'          => $merchant->branch_id,
            'number'             => $sequences->next('pickup_request'),
            'status'             => 'pending',
            'expected_count'     => $data['expected_count'],
            'address'            => $data['address'] ?? $merchant->address,
            'landmark'           => $merchant->landmark,
            'contact_phone'      => $data['contact_phone'] ?? $merchant->phone,
            'requested_at'       => now(),
            'scheduled_at'       => $data['scheduled_at'] ?? null,
            'notes'              => $data['notes'] ?? null,
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['number' => $pickup->number, 'message' => "أُرسل طلب استلام برقم {$pickup->number}."], 201);
    }
}
