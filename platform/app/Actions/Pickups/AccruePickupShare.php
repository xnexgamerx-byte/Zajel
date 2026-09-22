<?php

namespace App\Actions\Pickups;

use App\Models\Courier;
use App\Models\PickupRequest;
use App\Models\PickupShare;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * احتساب حصّة مندوب الاستلام، والبتّ في اعتراضه.
 *
 * الاستلام من التاجر والتوصيل للزبون عمليتان بشخصين وحافزين مختلفين.
 * وكان حقل «عمولة الاستلام» يُملأ في شاشة المندوب ولا يكتبه أحد في
 * الدفتر: المندوب يجمع الطرود طوال الشهر ولا يُستحقّ له شيء.
 */
class AccruePickupShare
{
    public function __construct(protected Ledger $ledger) {}

    public function handle(PickupRequest $pickup, ?User $actor = null): ?PickupShare
    {
        $courier = $pickup->courier;

        if (! $courier || ! $pickup->actual_count) {
            return null;
        }

        $rate = (int) ($courier->commission_per_pickup ?? 0);

        if ($rate <= 0) {
            return null;
        }

        return DB::transaction(function () use ($pickup, $courier, $rate, $actor) {
            // طلب واحد حصّة واحدة: إعادة إغلاق الطلب لا تُضاعف الاستحقاق
            if ($existing = PickupShare::where('pickup_request_id', $pickup->id)->first()) {
                return $existing;
            }

            $count = (int) $pickup->actual_count;

            $share = PickupShare::create([
                'branch_id'         => $pickup->branch_id ?? $courier->branch_id,
                'courier_id'        => $courier->id,
                'pickup_request_id' => $pickup->id,
                'shipments_count'   => $count,
                'rate'              => $rate,
                'amount'            => $count * $rate,
                'status'            => 'accrued',
            ]);

            $this->ledger->recordPickupShare($share, $courier, $actor);

            return $share;
        });
    }

    /** المندوب يعترض: يقول كم جمع فعلاً ولماذا. */
    public function object(PickupShare $share, int $claimedCount, string $reason): PickupShare
    {
        if ($share->status !== 'accrued') {
            throw ValidationException::withMessages([
                'objection' => 'هذه الحصّة بُتّ فيها سلفاً.',
            ]);
        }

        $share->forceFill([
            'status'           => 'objected',
            'claimed_count'    => $claimedCount,
            'objection_reason' => $reason,
            'objected_at'      => now(),
        ])->save();

        return $share->refresh();
    }

    /**
     * البتّ في الاعتراض.
     *
     * القبول لا يُعدّل المبلغ القديم بل يُقيّد فرقه بحركة تحمل سببها،
     * فيبقى أمام المندوب سطران يشرحان كيف صار الرقم ما صار.
     */
    public function resolve(PickupShare $share, ?int $agreedCount, string $note, ?User $actor = null): PickupShare
    {
        if ($share->status !== 'objected') {
            throw ValidationException::withMessages(['objection' => 'لا اعتراض على هذه الحصّة.']);
        }

        return DB::transaction(function () use ($share, $agreedCount, $note, $actor) {
            if ($agreedCount === null) {
                $share->forceFill([
                    'status'              => 'rejected',
                    'resolved_at'         => now(),
                    'resolved_by_user_id' => $actor?->id,
                    'resolution_note'     => $note,
                ])->save();

                return $share->refresh();
            }

            $adjustment = ($agreedCount * (int) $share->rate) - (int) $share->amount;

            $share->forceFill([
                'status'              => 'adjusted',
                'adjustment'          => $adjustment,
                'resolved_at'         => now(),
                'resolved_by_user_id' => $actor?->id,
                'resolution_note'     => $note,
            ])->save();

            $this->ledger->recordShareAdjustment($share->refresh(), $adjustment, $actor, $note);

            return $share->refresh();
        });
    }
}
