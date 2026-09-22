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
            'cancelled_at'      => 'datetime',
            'courier_settled_at' => 'datetime',
            'merchant_settled_at' => 'datetime',
            'is_fragile'        => 'boolean',
            'allow_open'        => 'boolean',
            'is_invoiced'       => 'boolean',
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

    public function deliveryCourier(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'delivery_courier_id');
    }

    public function pickupCourier(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'pickup_courier_id');
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
        return $q->whereNotIn('status', array_column(ShipmentStatus::terminal(), 'value'));
    }

    public function scopeStatus(Builder $q, ShipmentStatus|string|array $status): Builder
    {
        $values = collect((array) $status)
            ->map(fn ($s) => $s instanceof ShipmentStatus ? $s->value : $s)
            ->all();

        return $q->whereIn('status', $values);
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

        if ($user->role === UserRole::Merchant) {
            return $q->where('merchant_id', $user->merchant_id ?? 0);
        }

        if ($user->role === UserRole::Courier) {
            return $q->where(fn (Builder $w) => $w
                ->where('delivery_courier_id', $user->courier_id ?? 0)
                ->orWhere('pickup_courier_id', $user->courier_id ?? 0));
        }

        if ($user->isBranchLimited()) {
            return $q->where('branch_id', $user->branch_id);
        }

        return $q;
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
