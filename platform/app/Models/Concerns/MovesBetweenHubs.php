<?php

namespace App\Models\Concerns;

use App\Models\Hub;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * ما ينتقل بين مركزين — الكيس وكشف النقل: يراه فرعا طرفيه وحدهما.
 *
 * موظّف البصرة يرى ما خرج من مراكز فرعه أو يتّجه إليها، ولا يفتح كيساً بين
 * بغداد والموصل برقمه. والفرع الرئيسي يرى الطرق كلّها.
 */
trait MovesBetweenHubs
{
    public function scopeVisibleTo(Builder $q, ?User $user): Builder
    {
        if (! $user?->isBranchLimited()) {
            return $q;
        }

        $hubs = fn () => Hub::query()->select('id')->where('branch_id', $user->branch_id);

        return $q->where(fn (Builder $w) => $w
            ->whereIn($q->qualifyColumn('from_hub_id'), $hubs())
            ->orWhereIn($q->qualifyColumn('to_hub_id'), $hubs()));
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->newQuery()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->visibleTo(auth()->user())
            ->first();
    }
}
