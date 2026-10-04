<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Hub extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * مركز الفرع — حيث يُسلَّم راجعُ تجّاره ويُستلَم ما يُرسَل إليه.
     *
     * الرئيسيّ أوّلاً ثم الأقدم، فالنتيجة ثابتة لا تتبدّل بترتيب الإدخال.
     */
    public static function forBranch(?int $branchId): ?self
    {
        if (! $branchId) {
            return null;
        }

        return static::active()
            ->where('branch_id', $branchId)
            ->orderByRaw("type = 'main' desc")
            ->orderBy('id')
            ->first();
    }

    /**
     * مركز الفرع، يُنشأ إن لم يكن له مركز (docs/plan/32).
     *
     * كان التسجيل وحده يُنشئ مركزاً — للفرع الرئيسي — وفرعٌ يُضاف من «الفروع» بلا مركز:
     * لا يُرسَل إليه كيسٌ ولا كشف نقل، وما يستلمه موظّفه يُسجَّل في مخزن الرئيسي
     * (ReceiveAtHub::hubOf) فلا يراه هو ولا يُفرز إليه راجع.
     */
    public static function ensureFor(Branch $branch): self
    {
        if ($hub = static::forBranch($branch->id)) {
            return $hub;
        }

        $code = 'HUB-'.$branch->id;

        return static::create([
            'branch_id'      => $branch->id,
            // رقم الفرع لا اسمه: فريدٌ في الشركة ولا يتغيّر إن تغيّر الاسم
            'code'           => static::where('code', $code)->exists() ? $code.'-'.Str::lower(Str::random(4)) : $code,
            'name'           => Str::limit('مركز فرز '.$branch->name, 160, ''),
            'type'           => $branch->is_main ? 'main' : 'branch',
            'governorate_id' => $branch->governorate_id,
            'is_active'      => true,
        ]);
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
