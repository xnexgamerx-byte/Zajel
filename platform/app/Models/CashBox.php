<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashBox extends Model
{
    use BelongsToCompany, SeenByBranch;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** صاحب «صندوق الدفع» إن كان صندوق موظّف */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * أين يدخل ما قبضه هذا الموظّف بيده: صندوقه إن كان له صندوق — كما في
     * «صناديق الدفع» في المعتاد — وإلّا صندوق الفرع.
     */
    public static function forActor(?User $actor, ?int $branchId): ?self
    {
        if ($actor && ($own = static::active()->where('user_id', $actor->id)->first())) {
            return $own;
        }

        return static::forBranch($branchId, strict: (bool) $actor?->isBranchLimited());
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->latest('id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'main'   => 'القاصة الرئيسية',
            'branch' => 'صندوق فرع',
            'petty'  => 'صندوق نثريّة',
            'employee' => 'صندوق موظّف',
            default  => $this->type,
        };
    }

    /**
     * صندوق الفرع، وإلّا القاصة الرئيسية.
     *
     * الشركة ذات الفرع الواحد لا تُجبَر على اختيار صندوق في كل عملية،
     * وذات الفروع تُصيب صندوقها الصحيح بلا إعداد إضافي.
     *
     * وموظّف فرعٍ غير الرئيسي (strict) لا يسقط إلى صندوق فرعٍ آخر: النقد في
     * يده بالبصرة لا في درج بغداد، وصندوقٌ لا يراه لا يُقيَّد فيه ما قبضه.
     */
    public static function forBranch(?int $branchId, bool $strict = false): ?self
    {
        return static::active()
            // صندوق الموظّف له وحده: لا يصير صندوق الفرع لغيره
            ->whereNull('user_id')
            ->when($strict, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->when($branchId, fn (Builder $q) => $q->orderByRaw('case when branch_id = ? then 0 else 1 end', [$branchId]))
            ->orderByRaw("case when type = 'main' then 0 else 1 end")
            ->orderBy('id')
            ->first();
    }
}
