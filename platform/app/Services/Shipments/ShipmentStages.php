<?php

namespace App\Services\Shipments;

use App\Enums\ShipmentStatus;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * «كل مراحل النقل» كما يعرفها موظّفو المعتاد: ست مجموعات، في كلٍّ مراحلها
 * (docs/plan/17 §٤) — مبنيّةً على حالاتنا ومعها ما يميّز المرحلة داخل الحالة
 * (الراجع عند المندوب أو على الرفّ، الواصل الذي لم يُحاسَب عليه التاجر).
 *
 * مصدرٌ واحد للّوحة وللقائمة: العدّاد في اللوحة والقائمة التي يفتحها يقرآن
 * الشرط نفسه، فلا يقول العدّاد ١٢ وتعرض القائمة ١٠.
 */
final class ShipmentStages
{
    /**
     * @return array<string, array{label: string, hint: string, stages: array<string, array{label: string, hint: string, tone: string, apply: Closure(Builder): Builder}>}>
     */
    public static function groups(): array
    {
        $status = fn (ShipmentStatus ...$statuses) => fn (Builder $q) => $q->whereIn(
            'shipments.status', array_map(fn (ShipmentStatus $s) => $s->value, $statuses),
        );

        // الواصل يبقى في اللوحة حتى يُحاسَب عليه التاجر: بعده خرج من العمل اليومي
        $unsettled = fn (Closure $then) => fn (Builder $q) => $then($q)->whereNull('shipments.merchant_settled_at');

        return [
            'customer' => ['label' => 'عند العميل', 'hint' => 'أُنشئت ولم تصل المخزن بعد', 'stages' => [
                'ready_to_print' => ['label' => 'جاهزة للطبع', 'tone' => 'slate',
                    'hint' => 'أُنشئت، ولم يُطلب لها مندوب استلام', 'apply' => $status(ShipmentStatus::Created)],
                'ready_for_pickup' => ['label' => 'جاهزة للبيك اب', 'tone' => 'slate',
                    'hint' => 'تنتظر مندوب الاستلام', 'apply' => $status(ShipmentStatus::PendingPickup)],
            ]],
            'warehouse' => ['label' => 'المخزن', 'hint' => 'في طريقها إلينا أو على رفوفنا', 'stages' => [
                'incoming' => ['label' => 'قادمة في الطريق', 'tone' => 'blue',
                    'hint' => 'استلمها مندوب الاستلام ولم تدخل المخزن', 'apply' => $status(ShipmentStatus::PickedUp)],
                'in_store' => ['label' => 'داخل المخزن', 'tone' => 'blue',
                    'hint' => 'على الرفّ تنتظر مندوب توصيل', 'apply' => $status(ShipmentStatus::AtHub)],
            ]],
            'courier' => ['label' => 'عند المندوب', 'hint' => 'خرجت للتوصيل ولم تُحسم', 'stages' => [
                'out_for_delivery' => ['label' => 'قيد التوصيل', 'tone' => 'blue',
                    'hint' => 'مع المندوب اليوم', 'apply' => $status(ShipmentStatus::OutForDelivery)],
                'to_process' => ['label' => 'شحنات للمعالجة', 'tone' => 'amber',
                    'hint' => 'محاولة فاشلة: تُعاد أو تؤجَّل أو تُرجع', 'apply' => $status(ShipmentStatus::FailedAttempt)],
                'postponed' => ['label' => 'مؤجّلة', 'tone' => 'amber',
                    'hint' => 'بطلب الزبون إلى موعدٍ آخر', 'apply' => $status(ShipmentStatus::Postponed)],
                'return_with_courier' => ['label' => 'راجع عند المندوب', 'tone' => 'amber',
                    'hint' => 'قُرّر إرجاعها وما زالت بيده',
                    'apply' => fn (Builder $q) => $q->where('shipments.status', ShipmentStatus::Returning->value)
                        ->whereNull('shipments.return_received_at')],
            ]],
            'returns' => ['label' => 'الراجع', 'hint' => 'عائدةٌ إلى أصحابها', 'stages' => [
                'return_on_shelf' => ['label' => 'راجعة في المخزن', 'tone' => 'slate',
                    'hint' => 'استُلمت من المندوب ولم تُسلَّم لتاجرها', 'apply' => fn (Builder $q) => $q->returnOnShelf()],
            ]],
            'delivered' => ['label' => 'الواصل', 'hint' => 'سُلّمت ولم يُحاسَب عليها التاجر بعد', 'stages' => [
                'delivered' => ['label' => 'سُلّمت بنجاح', 'tone' => 'green',
                    'hint' => 'بمبلغها كما هو',
                    'apply' => $unsettled(fn (Builder $q) => $q->where('shipments.status', ShipmentStatus::Delivered->value)
                        ->where('shipments.type', '!=', 'exchange')
                        ->whereColumn('shipments.collected_amount', 'shipments.cod_amount'))],
                'partial_or_exchange' => ['label' => 'تسليم جزئي أو استبدال', 'tone' => 'green',
                    'hint' => 'سُلّم بعضها، أو بُدّلت بطردٍ آخر',
                    'apply' => $unsettled(fn (Builder $q) => $q->where(fn (Builder $w) => $w
                        ->where('shipments.status', ShipmentStatus::PartiallyDelivered->value)
                        ->orWhere(fn (Builder $x) => $x->where('shipments.status', ShipmentStatus::Delivered->value)
                            ->where('shipments.type', 'exchange'))))],
                'amount_changed' => ['label' => 'سُلّمت مع تغيير المبلغ', 'tone' => 'amber',
                    'hint' => 'المحصَّل غير المطلوب',
                    'apply' => $unsettled(fn (Builder $q) => $q->where('shipments.status', ShipmentStatus::Delivered->value)
                        ->where('shipments.type', '!=', 'exchange')
                        ->whereColumn('shipments.collected_amount', '!=', 'shipments.cod_amount'))],
            ]],
            'branches' => ['label' => 'النقل بين الفروع', 'hint' => 'بين مركزٍ وآخر', 'stages' => [
                'in_transit' => ['label' => 'في الطريق بين الفروع', 'tone' => 'blue',
                    'hint' => 'في كيسٍ على كشف نقل', 'apply' => $status(ShipmentStatus::InTransit)],
                'returns_to_sort' => ['label' => 'رواجع الفروع في المخزن', 'tone' => 'amber',
                    'hint' => 'راجعٌ على رفّنا وتاجره في فرعٍ آخر',
                    'apply' => fn (Builder $q) => $q->returnOnShelf()->awayFromHomeBranch()],
                'returns_on_the_way' => ['label' => 'رواجع في الطريق إلى فروعها', 'tone' => 'blue',
                    'hint' => 'كُيِّست إلى فرع تاجرها',
                    'apply' => fn (Builder $q) => $q->where('shipments.status', ShipmentStatus::Returning->value)
                        ->whereNotNull('shipments.return_received_at')
                        ->whereNotNull('shipments.current_bag_id')],
            ]],
        ];
    }

    /** @return array{label: string, hint: string, tone: string, apply: Closure, group: string}|null */
    public static function find(?string $key): ?array
    {
        foreach (static::groups() as $group) {
            if ($key !== null && isset($group['stages'][$key])) {
                return $group['stages'][$key] + ['group' => $group['label']];
            }
        }

        return null;
    }

    /** يقصر الاستعلام على المرحلة؛ والمرحلة المجهولة لا تقصر شيئاً. */
    public static function apply(Builder $query, ?string $key): Builder
    {
        $stage = static::find($key);

        return $stage ? ($stage['apply'])($query) : $query;
    }
}
