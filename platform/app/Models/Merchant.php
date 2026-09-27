<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];

    /** أنواع البضاعة كما في «متاجر الفرع» في المعتاد — تصنيفٌ يُفلتر به ويُقرأ في التقارير */
    public const GOODS_TYPES = [
        'general'     => 'عامّة',
        'clothing'    => 'ملابس',
        'supplies'    => 'مستلزمات',
        'dental'      => 'مستلزمات أسنان',
        'dental_tools' => 'أدوات طلاب أسنان',
        'hardware'    => 'عدد وإنشائية',
        'household'   => 'منزلية',
        'electronics' => 'إلكترونيات',
        'cosmetics'   => 'تجميل وعطور',
        'food'        => 'أغذية',
        'other'       => 'أخرى',
    ];

    /** طرق الدفع للتاجر — في بطاقته، وفي طلب الدفع من بوابته، وفي تسجيل الدفع */
    public const PAYOUT_METHODS = [
        'cash'          => 'نقد',
        'zaincash'      => 'زين كاش',
        'asiahawala'    => 'آسيا حوالة',
        'fastpay'       => 'فاست باي',
        'qi'            => 'Qi كارد',
        'fib'           => 'FIB',
        'bank_transfer' => 'حوالة مصرفية',
    ];

    protected function casts(): array
    {
        return ['is_vip' => 'boolean', 'portal_access' => 'boolean',
                'requires_delivery_code' => 'boolean', 'hold_for_review' => 'boolean', 'can_process' => 'boolean'];
    }

    public function goodsTypeLabel(): ?string
    {
        return self::GOODS_TYPES[$this->goods_type] ?? null;
    }

    /** مندوب الاستلام الذي يخدمه عادةً: يُقترح أوّلاً حين يطلب استلاماً */
    public function pickupCourier(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'pickup_courier_id');
    }

    /** موظّف المبيعات الذي جاء به */
    public function salesUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /** قائمة التسعير الفعّالة: الخاصة بالتاجر، وإلّا افتراضية الشركة. */
    public function effectivePriceList(): ?PriceList
    {
        return $this->priceList
            ?? PriceList::where('is_default', true)->where('is_active', true)->first();
    }
}
