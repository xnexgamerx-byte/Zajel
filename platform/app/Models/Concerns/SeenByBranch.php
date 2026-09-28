<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * ما له فرع: موظّف فرعٍ غير الرئيسي يرى ما لفرعه وحده، ولا يفتح غيره برقمه.
 *
 * الفرع يُقرأ من عمود branch_id، أو — لما لا عمود له — من فرع تاجره أو مندوبه
 * (branchThrough). وربط المسار يمرّ بالنطاق نفسه كما تُربط الشحنة: سجلّ فرعٍ آخر
 * يُردّ 404 في كل مسارٍ يربطه اليوم وما يُضاف غداً، بلا سطرٍ يُتذكَّر في كل متحكّم.
 *
 * نطاقٌ يُطلب لا نطاقٌ عامّ: الدفتر والتسويات تقرأ تاجر فرعٍ آخر حين يسلّم مندوب
 * البصرة شحنةً لتاجرٍ من بغداد، ونطاقٌ عامّ كان سيُخفيه عنها فيُفسد الحساب.
 */
trait SeenByBranch
{
    public function scopeVisibleTo(Builder $q, ?User $user): Builder
    {
        if (! $user?->isBranchLimited()) {
            return $q;
        }

        if (($through = $this->branchThrough()) === null) {
            return $q->where($q->qualifyColumn('branch_id'), $user->branch_id);
        }

        // فرعُ صاحبه ولو حُذف صاحبه: سجلّ تاجرٍ محذوف يبقى لفرعه
        return $q->whereIn(
            $q->qualifyColumn($through.'_id'),
            $this->{$through}()->getRelated()->newQueryWithoutScopes()->select('id')->where('branch_id', $user->branch_id),
        );
    }

    /** الفرع من عموده (null)، أو من علاقةٍ لها فرع: 'merchant' أو 'courier'. */
    protected function branchThrough(): ?string
    {
        return null;
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->newQuery()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->visibleTo(auth()->user())
            ->first();
    }
}
