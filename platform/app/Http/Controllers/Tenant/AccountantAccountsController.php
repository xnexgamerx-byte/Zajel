<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «حسابات المحاسب» كما في المعتاد: ما قبضه كل موظّفٍ وما دفعه في المدّة،
 * بأنواعه — من دفتر القاصة نفسه، فلا رقمان لحركةٍ واحدة.
 */
class AccountantAccountsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();
        $userId = $request->integer('user_id') ?: null;

        // حركات صناديق فرعه وموظّفيه وحدها إن كان مقيَّداً بفرع
        $base = fn () => CashMovement::query()
            ->visibleTo($request->user())
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('created_by_user_id')
            ->when($userId, fn ($q) => $q->where('created_by_user_id', $userId));

        $rows = $base()
            ->selectRaw("created_by_user_id, category,
                sum(case when direction = 'in' then amount else 0 end) as total_in,
                sum(case when direction = 'out' then amount else 0 end) as total_out,
                count(*) as moves")
            ->groupBy('created_by_user_id', 'category')
            ->toBase()
            ->get()
            ->groupBy('created_by_user_id');

        return view('tenant.money.accountants', [
            'period'    => $period,
            'userId'    => $userId,
            'rows'      => $rows,
            'users'     => User::whereIn('id', $rows->keys()->merge($userId ? [$userId] : []))->orderBy('name')->get(['id', 'name'])->keyBy('id'),
            'everyone'  => User::whereNotIn('role', [UserRole::Courier->value, UserRole::Merchant->value])
                ->visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
            'movements' => $userId
                ? $base()->with('cashBox:id,name')->latest('id')->paginate(config('zajel.per_page'))->withQueryString()
                : null,
        ]);
    }
}
