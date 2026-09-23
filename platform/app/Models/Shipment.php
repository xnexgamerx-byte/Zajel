<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shipment extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'            => ShipmentStatus::class,
            'status_changed_at' => 'datetime',
            'scheduled_at'      => 'datetime',
            'picked_up_at'      => 'datetime',
            'assigned_at'       => 'datetime',
            'delivered_at'      => 'datetime',
            'returned_at'       => 'datetime',
            'return_received_at' => 'datetime',
            'amount_confirmed'  => 'boolean',
            'amount_confirmed_at' => 'datetime',
            'cancelled_at'      => 'datetime',
            'courier_settled_at' => 'datetime',
            'merchant_settled_at' => 'datetime',
            'is_fragile'        => 'boolean',
            'allow_open'        => 'boolean',
            'is_invoiced'       => 'boolean',
            'is_forced'         => 'boolean',
            'duplicate_cleared_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- علاقات

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    /** الكيس الذي فيه الآن — فارغ على الرفّ أو بيد المندوب. */
    public function currentBag(): BelongsTo
    {
        return $this->belongsTo(Bag::class, 'current_bag_id');
    }

    public function deliveryCourier(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'delivery_courier_id');
    }

    public function pickupCourier(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'pickup_courier_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'duplicate_of_id');
    }

    public function forcedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forced_by_user_id');
    }

    public function lastFailureReason(): BelongsTo
    {
        return $this->belongsTo(FailureReason::class, 'last_failure_reason_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('id');
    }

    public function latestEvent(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->latest('id')->limit(1);
    }

    // ---------------------------------------------------------------- نطاقات

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', ShipmentStatus::openValues());
    }

    public function scopeStatus(Builder $q, ShipmentStatus|string|array $status): Builder
    {
        $values = collect((array) $status)
            ->map(fn ($s) => $s instanceof ShipmentStatus ? $s->value : $s)
            ->all();

        return $q->whereIn('status', $values);
    }

    /**
     * راجعٌ وصل المخزن ولم يُكيَّس بعد — المادّة التي يُقسَم عليها بين
     * «يُسلَّم هنا» و«يُفرَز لفرع تاجره».
     */
    public function scopeReturnOnShelf(Builder $q): Builder
    {
        return $q->where('shipments.status', ShipmentStatus::Returning->value)
            ->whereNotNull('shipments.return_received_at')
            ->whereNull('shipments.current_bag_id');
    }

    /**
     * في غير فرع تاجره: مكانه معروف، وفرع تاجره معروف، ويختلفان.
     *
     * والمجهول ليس بعيداً: شركةٌ بفرعٍ واحد لا تعرف مراكزَ أصلاً،
     * وراجعُها في فرعها بالضرورة. ولذلك (أ <> ب) تُلفّ بـ coalesce:
     * أيّ طرفٍ فارغ يجعل المقارنة NULL، والـ NULL هنا «هنا» لا «هناك».
     */
    public function scopeAwayFromHomeBranch(Builder $q, bool $away = true): Builder
    {
        return $q->whereRaw(
            'coalesce((select h.branch_id from hubs h where h.id = shipments.hub_id)'
            .' <> (select m.branch_id from merchants m where m.id = shipments.merchant_id), 0) = ?',
            [$away ? 1 : 0],
        );
    }

    /**
     * البحث الموحّد الذي تستعمله خدمة العملاء: رقم وصل، باركود،
     * رقم طلب التاجر، أو رقم هاتف المستلم.
     */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $q;
        }

        return $q->where(function (Builder $w) use ($term) {
            $w->where('number', $term)
                ->orWhere('barcode', $term)
                ->orWhere('merchant_reference', $term)
                ->orWhere('recipient_phone', $term)
                ->orWhere('recipient_phone_alt', $term);

            // بحث جزئي بالاسم فقط عند 3 أحرف فأكثر — حتى لا نمسح الجدول
            if (mb_strlen($term) >= 3) {
                $w->orWhere('recipient_name', 'like', $term.'%')
                    ->orWhere('number', 'like', $term.'%');
            }
        });
    }

    /**
     * ما يحقّ لهذا المستخدم رؤيته داخل شركته.
     *
     * CompanyScope يمنع رؤية شركة أخرى؛ وهذا يمنع رؤية ما لا يخصّك
     * داخل شركتك: التاجر يرى شحناته وحدها، والمندوب ما أُسنِد إليه،
     * والموظّف المقيّد بفرع فرعَه. بلا هذا، حساب تاجر واحد يكشف
     * أسعار كل التجّار الآخرين وأرقام زبائنهم.
     */
    public function scopeVisibleTo(Builder $q, ?User $user): Builder
    {
        if (! $user) {
            return $q;
        }

        /*
        | الأعمدة مؤهَّلة باسم الجدول دائماً.
        |
        | كانت where('branch_id', …) مجرّدة، فكل تقرير يضمّ جدولاً آخر
        | فيه branch_id — التجّار، المناديب — يسقط بـ «ambiguous column»
        | لمدير الفرع وحده: ثلاثة تقارير كانت تُرجع 500 لكل مدير فرع،
        | والاختبارات كلّها تعمل بصاحب الشركة فلم يرَها أحد.
        */
        if ($user->role === UserRole::Merchant) {
            return $q->where($q->qualifyColumn('merchant_id'), $user->merchant_id ?? 0);
        }

        if ($user->role === UserRole::Courier) {
            return $q->where(fn (Builder $w) => $w
                ->where($q->qualifyColumn('delivery_courier_id'), $user->courier_id ?? 0)
                ->orWhere($q->qualifyColumn('pickup_courier_id'), $user->courier_id ?? 0));
        }

        /*
        | الفرع يرى شحنات تجّاره، وما وصل مركزه ليوزّعه.
        |
        | branch_id فرعُ المنشأ (فرع التاجر)، فشحنةٌ من تاجرٍ في بغداد
        | إلى زبونٍ في البصرة لم يكن يراها موظّف البصرة ولو كانت على
        | رفّه تنتظر مندوباً منه. hub_id مكانها الآن، فيدخل به.
        */
        if ($user->isBranchLimited()) {
            return $q->where(fn (Builder $w) => $w
                ->where($q->qualifyColumn('branch_id'), $user->branch_id)
                ->orWhereIn($q->qualifyColumn('hub_id'), Hub::query()->select('id')->where('branch_id', $user->branch_id)));
        }

        return $q;
    }

    /**
     * ربط المسار بالشحنة يمرّ بـ visibleTo لمن سجّل دخوله.
     *
     * كانت أربعة مسارات تكتب على شحنةٍ برقمها — تغيير الحالة، وتأكيد
     * المبلغ الذي لا رجعة فيه، وحسم التكرار مرّتين — بلا فحص رؤية: صفحة
     * الشحنة تُرجع 404 لموظّف فرعٍ آخر، والإرسال إليها يمرّ. والفحص هنا
     * يغطّي كل مسارٍ فيه {shipment} اليوم وما يُضاف غداً، للموظّف والمندوب
     * والتاجر معاً، فلا يتوقّف على تذكّر سطرٍ في كل متحكّم.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $query = $this->newQuery()->where($field ?? $this->getRouteKeyName(), $value);

        if ($user = auth()->user()) {
            $query->visibleTo($user);
        }

        return $query->first();
    }

    // ---------------------------------------------------------------- مساعدات

    public function isTerminal(): bool
    {
        return ! $this->status->isOpen();
    }

    /** ما يستحقّه التاجر عن هذه الشحنة بعد كل الخصومات. */
    public function computeMerchantDue(): int
    {
        return $this->fees_paid_by === 'customer'
            ? $this->collected_amount - $this->cod_fee + $this->discount
            : $this->collected_amount - $this->total_fees;
    }
}
