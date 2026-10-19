{{--
  «العوائد» قسم المدير للأرباح: لوحة الأرباح، ووجبات التحصيل وسجلّها، وتقرير المدّة، وسحب الأرباح.
--}}
<div class="mb-4 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">{{ $title }}</h1>
        @isset($lead)<p class="mt-1 max-w-3xl text-sm text-ink-500">{{ $lead }}</p>@endisset
    </div>
    <a href="{{ route('profits.batches.create') }}" class="btn-primary"><x-icon name="plus" class="size-4"/> إنشاء وجبة تحصيل</a>
</div>
<nav class="tab-nav mb-5" aria-label="العوائد">
    @foreach (array_filter([
        ['profits.index', 'لوحة الأرباح', ['profits.index']],
        ['profits.batches.index', 'سجل وجبات التحصيل', ['profits.batches.*']],
        ['profits.report', 'تقرير الأرباح', ['profits.report']],
        auth()->user()->isBranchLimited() ? null : ['profits.withdrawals.index', 'سحب الأرباح', ['profits.withdrawals.*']],
    ]) as [$route, $label, $patterns])
        <a href="{{ route($route) }}" @class(['tab-link', 'tab-link-active' => request()->routeIs(...$patterns)])
           @if (request()->routeIs(...$patterns)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
