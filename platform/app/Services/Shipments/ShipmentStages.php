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
 *
 * ولكل مرحلةٍ شاشاتها: ما يُعمل بشحناتها (استلامٌ بالمسح، معالجة، تسليم راجع…)
 * يظهر في قسمها من «كل مراحل النقل» — [المسار، النصّ، الصلاحية].
 */
final class ShipmentStages
{
    /**
     * @return array<string, array{label: string, hint: string, stages: array<string, array{label: string, hint: string, tone: string, apply: Closure(Builder): Builder, links?: list<array{0: string, 1: string, 2: string}>}>}>
     */
    public static function groups(): array
    {
        $status = fn (ShipmentStatus ...$statuses) => fn (Builder $q) => $q->whereIn(
            'shipments.status', array_map(fn (ShipmentStatus $s) => $s->value, $statuses),
        );

        // الواصل يبقى في اللوحة حتى يُحاسَب عليه التاجر: بعده خرج من العمل اليومي
        $unsettled = fn (Closure $then) => fn (Builder $q) => $then($q)->whereNull('shipments.merchant_settled_at');

        return [
            'customer' => ['label' => 'عند التاجر', 'hint' => 'أُنشئت ولم تصل المخزن بعد', 'stages' => [
                'ready_to_print' => ['label' => 'جاهزة للطبع', 'tone' => 'slate',
                    'links' => [['pickups.index', 'طلبات الاستلام', 'pickups.manage']],
                    'hint' => 'أُنشئت، ولم يُطلب لها مندوب استلام', 'apply' => $status(ShipmentStatus::Created)],
                'ready_for_pickup' => ['label' => 'جاهزة للبيك اب', 'tone' => 'slate',
                    'links' => [['pickups.index', 'طلبات الاستلام', 'pickups.manage']],
                    'hint' => 'تنتظر مندوب الاستلام', 'apply' => $status(ShipmentStatus::PendingPickup)],
            ]],
            'warehouse' => ['label' => 'المخزن', 'hint' => 'في طريقها إلينا أو على رفوفنا', 'stages' => [
                'incoming' => ['label' => 'بالطريق للمخزن', 'tone' => 'blue',
                    'links' => [['shipments.scan', 'استلام بالمسح', 'shipments.status']],
                    'hint' => 'استلمها مندوب الاستلام ولم تدخل المخزن', 'apply' => $status(ShipmentStatus::PickedUp)],
                'in_store' => ['label' => 'بالمخزن', 'tone' => 'blue',
                    'links' => [['shipments.scan', 'استلام وإسناد بالمسح', 'shipments.status'], ['courier-manifests.index', 'كشوف المناديب', 'transport.manage']],
                    'hint' => 'على الرفّ تنتظر مندوب توصيل', 'apply' => $status(ShipmentStatus::AtHub)],
            ]],
            'courier' => ['label' => 'عند المندوب', 'hint' => 'خرجت للتوصيل ولم تُحسم', 'stages' => [
                'out_for_delivery' => ['label' => 'قيد التوصيل', 'tone' => 'blue',
                    'links' => [['courier-manifests.index', 'كشوف المناديب', 'transport.manage'], ['couriers.cash', 'نقد المندوبين', 'money.view']],
                    'hint' => 'مع المندوب اليوم', 'apply' => $status(ShipmentStatus::OutForDelivery)],
                'to_process' => ['label' => 'شحنات للمعالجة', 'tone' => 'amber',
                    'links' => [['processing.index', 'شاشة المعالجة', 'shipments.status']],
                    'hint' => 'محاولة فاشلة: تُعاد أو تؤجَّل أو تُرجع', 'apply' => $status(ShipmentStatus::FailedAttempt)],
                // المعتاد يسمّيها ولا يصفها (docs/plan/22 §٢): ما سلّمه المندوب بغير ما طُلب
                // ينتظر من يعتمد مبلغه قبل أن يُبنى عليه حساب — والاعتماد تأكيد المبلغ نفسه
                'awaiting_approval' => ['label' => 'انتظار موافقة التسليم', 'tone' => 'amber',
                    'hint' => 'سُلّمت جزئياً أو بمبلغٍ غير المطلوب: يُعتمد مبلغها قبل التسوية',
                    'apply' => fn (Builder $q) => self::awaitingApproval($q)],
                'postponed' => ['label' => 'مؤجل', 'tone' => 'amber',
                    'links' => [['processing.index', 'شاشة المعالجة', 'shipments.status']],
                    'hint' => 'بطلب الزبون إلى موعدٍ آخر', 'apply' => $status(ShipmentStatus::Postponed)],
                'return_with_courier' => ['label' => 'راجع عند المندوب', 'tone' => 'amber',
                    'links' => [['returns.incoming', 'استلام الراجع من المندوب', 'returns.manage']],
                    'hint' => 'قُرّر إرجاعها وما زالت بيده',
                    'apply' => fn (Builder $q) => $q->where('shipments.status', ShipmentStatus::Returning->value)
                        ->whereNull('shipments.return_received_at')],
            ]],
            'returns' => ['label' => 'الراجع', 'hint' => 'عائدةٌ إلى أصحابها', 'stages' => [
                'return_on_shelf' => ['label' => 'راجع بالمخزن', 'tone' => 'slate',
                    'links' => [['returns.outgoing', 'تسليم الراجع للتاجر', 'returns.manage'], ['returns.pickup', 'تسليمه لمندوب الاستلام', 'returns.manage']],
                    'hint' => 'استُلمت من المندوب ولم تُسلَّم لتاجرها', 'apply' => fn (Builder $q) => $q->returnOnShelf()],
            ]],
            'delivered' => ['label' => 'الواصل', 'hint' => 'سُلّمت ولم يُحاسَب عليها التاجر بعد', 'stages' => [
                'delivered' => ['label' => 'واصل', 'tone' => 'green',
                    'links' => [['settlements.merchants.index', 'تسوية التجّار', 'money.view']],
                    'hint' => 'بمبلغها كما هو',
                    'apply' => $unsettled(fn (Builder $q) => $q->where('shipments.status', ShipmentStatus::Delivered->value)
                        ->where('shipments.type', '!=', 'exchange')
                        ->whereColumn('shipments.collected_amount', 'shipments.cod_amount'))],
                'partial_or_exchange' => ['label' => 'واصل جزئي أو تبديل', 'tone' => 'green',
                    'links' => [['settlements.merchants.index', 'تسوية التجّار', 'money.view']],
                    'hint' => 'سُلّم بعضها، أو بُدّلت بطردٍ آخر',
                    'apply' => $unsettled(fn (Builder $q) => $q->where(fn (Builder $w) => $w
                        ->where('shipments.status', ShipmentStatus::PartiallyDelivered->value)
                        ->orWhere(fn (Builder $x) => $x->where('shipments.status', ShipmentStatus::Delivered->value)
                            ->where('shipments.type', 'exchange'))))],
                'amount_changed' => ['label' => 'واصل بتغيير المبلغ', 'tone' => 'amber',
                    'links' => [['settlements.merchants.index', 'تسوية التجّار', 'money.view']],
                    'hint' => 'المحصَّل غير المطلوب',
                    'apply' => $unsettled(fn (Builder $q) => $q->where('shipments.status', ShipmentStatus::Delivered->value)
                        ->where('shipments.type', '!=', 'exchange')
                        ->whereColumn('shipments.collected_amount', '!=', 'shipments.cod_amount'))],
            ]],
            'branches' => ['label' => 'النقل بين الفروع', 'hint' => 'بين مركزٍ وآخر', 'stages' => [
                'in_transit' => ['label' => 'بالطريق بين الفروع', 'tone' => 'blue',
                    'links' => [['manifests.inbound', 'وارد المراكز', 'transport.manage'], ['manifests.index', 'كشوف النقل', 'transport.manage']],
                    'hint' => 'في كيسٍ على كشف نقل', 'apply' => $status(ShipmentStatus::InTransit)],
                'returns_to_sort' => ['label' => 'رواجع الفروع بالمخزن', 'tone' => 'amber',
                    'links' => [['returns.sorting', 'فرز الراجع للفروع', 'returns.manage']],
                    'hint' => 'راجعٌ على رفّنا وتاجره في فرعٍ آخر',
                    'apply' => fn (Builder $q) => $q->returnOnShelf()->awayFromHomeBranch()],
                'returns_on_the_way' => ['label' => 'رواجع بالطريق لفروعها', 'tone' => 'blue',
                    'links' => [['bags.index', 'الأكياس', 'transport.manage']],
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

    /**
     * «انتظار موافقة التسليم»: سلّمها المندوب جزئياً أو بمبلغٍ غير المطلوب، ولم يُعتمد
     * مبلغها (ConfirmAmount) ولم تدخل تسويةً بعد. تخرج حين يُعتمد — من القائمة دفعةً
     * (ShipmentAmountController::approve) أو من صفحتها بمبلغٍ مصحَّح.
     */
    public static function awaitingApproval(Builder $q): Builder
    {
        return $q->whereIn('shipments.status', [ShipmentStatus::Delivered->value, ShipmentStatus::PartiallyDelivered->value])
            ->where('shipments.amount_confirmed', false)
            ->whereNull('shipments.courier_settlement_id')
            ->whereNull('shipments.merchant_settlement_id')
            ->whereNull('shipments.merchant_settled_at')
            ->where(fn (Builder $w) => $w->where('shipments.status', ShipmentStatus::PartiallyDelivered->value)
                ->orWhereColumn('shipments.collected_amount', '!=', 'shipments.cod_amount'));
    }

    /** يقصر الاستعلام على المرحلة؛ والمرحلة المجهولة لا تقصر شيئاً. */
    public static function apply(Builder $query, ?string $key): Builder
    {
        $stage = static::find($key);

        return $stage ? ($stage['apply'])($query) : $query;
    }
}
