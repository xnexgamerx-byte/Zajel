<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Courier extends Model
{
    use BelongsToCompany, SeenByBranch, SoftDeletes;

    protected $guarded = ['id'];

    /** حصّة المركز من أرباح مندوب الاستلام الشريك — «نسبة/مبلغ للمركز» */
    public const PARTNER_CENTRE = [
        'none'    => 'ليس شريكاً',
        'percent' => 'نسبة للمركز',
        'amount'  => 'مبلغ للمركز',
    ];

    protected function casts(): array
    {
        return ['is_available' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** حساب دخوله على تطبيق المندوبين، إن كان له حساب */
    public function loginAccount(): ?User
    {
        return $this->user ?? User::where('courier_id', $this->id)
            ->where('role', \App\Enums\UserRole::Courier->value)->orderBy('id')->first();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** «مندوب التوصيل الأب»: من يعمل هذا تحته، ويُسوّى معه */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'parent_id');
    }

    /** «المندوبون الفرعيّون» */
    public function subs(): HasMany
    {
        return $this->hasMany(Courier::class, 'parent_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(PickupPayout::class);
    }

    public function isSub(): bool
    {
        return $this->parent_id !== null;
    }

    /** هو وفريقه — لفلتر «المندوب» الذي يشمل الفرعيّين */
    public static function teamIds(int $courierId): array
    {
        return static::query()->where('parent_id', $courierId)->pluck('id')->prepend($courierId)->all();
    }

    public function isPartner(): bool
    {
        return in_array($this->partner_centre_type, ['percent', 'amount'], true);
    }

    /** حصّة المركز من مستحقٍّ قدره $due: لا تتجاوزه ولا تنقص عن صفر */
    public function centreCut(int $due): int
    {
        return match ($this->partner_centre_type) {
            'percent' => min($due, intdiv($due * min(100, (int) $this->partner_centre_value), 100)),
            'amount'  => min($due, (int) $this->partner_centre_value),
            default   => 0,
        };
    }

    public function partnerLabel(): ?string
    {
        return match ($this->partner_centre_type) {
            'percent' => 'شريك — للمركز '.$this->partner_centre_value.'٪',
            'amount'  => 'شريك — للمركز '.number_format((int) $this->partner_centre_value).' د.ع',
            default   => null,
        };
    }

    /**
     * أجرته عن تسليم هذه الشحنة: ما في بطاقته إن كُتب، وإلّا «أجرة المندوب»
     * لمحافظة وجهتها — لأقضيتها وأطرافها إن كانت منطقتها طرفية.
     */
    public function payForDelivery(Shipment $shipment): int
    {
        if ($this->commission_per_delivery !== null) {
            return (int) $this->commission_per_delivery;
        }

        $setting = GovernorateSetting::where('governorate_id', $shipment->governorate_id)->first();

        if (! $setting) {
            return 0;
        }

        $peripheral = $shipment->city_id !== null
            && CitySetting::where('city_id', $shipment->city_id)->where('is_peripheral', true)->exists();

        return (int) (($peripheral ? $setting->courier_fee_peripheral : null) ?? $setting->courier_fee ?? 0);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(CourierZone::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Shipment::class, 'delivery_courier_id');
    }

    public function pickupShares(): HasMany
    {
        return $this->hasMany(PickupShare::class);
    }

    public function pickups(): HasMany
    {
        return $this->hasMany(Shipment::class, 'pickup_courier_id');
    }

    /** يوصّل الطلبات. */
    public function scopeDelivering(Builder $q): Builder
    {
        return $q->whereIn('type', ['delivery', 'both']);
    }

    /** يستلم من التجّار. */
    public function scopePicking(Builder $q): Builder
    {
        return $q->whereIn('type', ['pickup', 'both']);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function delivers(): bool
    {
        return in_array($this->type, ['delivery', 'both'], true);
    }

    public function picks(): bool
    {
        return in_array($this->type, ['pickup', 'both'], true);
    }

    public function hasReachedCashLimit(): bool
    {
        return $this->cash_limit > 0 && $this->cash_in_hand >= $this->cash_limit;
    }

    /** ما يجب أن يسلّمه للشركة الآن: النقد الذي بيده ناقص عمولته. */
    public function netDue(): int
    {
        return $this->cash_in_hand - $this->commission_balance;
    }
}
