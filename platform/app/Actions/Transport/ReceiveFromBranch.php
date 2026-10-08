<?php

namespace App\Actions\Transport;

use App\Enums\ShipmentStatus;
use App\Models\Manifest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «استلمت الكل» (docs/plan/38): كشف النقل الوارد يُستلم وتُفتح أكياسه بضغطةٍ واحدة.
 *
 * كان الاستلام خطوتين في شاشتين — استلام الكشف، ثم فتح كل كيس — وكيسٌ استُلم ولم يُفتح
 * تبقى شحناته «بالطريق» وراجعه معلّقاً، فيُستلم بتغيير حالته بيده فيعود شحنةً جديدة. هنا:
 * الشحنات تدخل المخزن هنا، والراجع يصل راجعاً على رفّ فرع تاجره جاهزاً للتسليم.
 *
 * وكيسٌ لم يصل يُعلَّم مفقوداً كما في RunManifest::receive — بالاستلام من الكشف نفسه.
 */
class ReceiveFromBranch
{
    public function __construct(
        protected RunManifest $manifests,
        protected BagShipments $bags,
    ) {}

    /**
     * @param  list<int>|null  $bagIds  الأكياس التي وصلت؛ null = كلّها
     * @return array{shipments: int, returns: int}
     */
    public function handle(Manifest $manifest, User $actor, ?array $bagIds = null, ?string $notes = null): array
    {
        return DB::transaction(function () use ($manifest, $actor, $bagIds, $notes) {
            $manifest = Manifest::query()->lockForUpdate()->findOrFail($manifest->id);

            if ($manifest->status === 'dispatched') {
                $this->manifests->receive($manifest, $bagIds ?? $manifest->bags()->pluck('bags.id')->all(), $actor, $notes);
            } elseif ($manifest->status !== 'arrived') {
                throw ValidationException::withMessages(['manifest' => "الكشف {$manifest->code} ليس في الطريق إليك."]);
            }

            $shipments = 0;
            $returns = 0;

            // الأكياس التي وصلت ولم تُفتح — ومنها ما استُلم قبل هذه الشاشة ولم يُفتح
            foreach ($manifest->bags()->where('bags.status', 'received')->get() as $bag) {
                $inside = $bag->shipments()->wherePivotNull('removed_at')->get();
                $returns += $inside->where('status', ShipmentStatus::Returning)->count();
                $shipments += $inside->count();

                $this->bags->open($bag, $actor);
            }

            return ['shipments' => $shipments, 'returns' => $returns];
        });
    }
}
