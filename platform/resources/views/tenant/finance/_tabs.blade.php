{{-- دفتر القيود (docs/plan/68): الميزان ومطابقته، وسطور القيود لتتبّع أي رقم --}}
<div class="mb-4">
    <h1 class="page-title">{{ $title }}</h1>
    @isset($lead)<p class="mt-1 max-w-3xl text-sm text-ink-500">{{ $lead }}</p>@endisset
</div>
<nav class="tab-nav mb-5" aria-label="دفتر القيود">
    @foreach ([
        ['finance.trial-balance', 'ميزان المراجعة والمطابقة', ['finance.trial-balance']],
        ['finance.ledger', 'دفتر القيود', ['finance.ledger', 'finance.entries.*']],
        ['finance.periods.index', 'إقفال الفترات', ['finance.periods.*']],
        ['finance.opening', 'الأرصدة الافتتاحية', ['finance.opening']],
    ] as [$route, $label, $patterns])
        <a href="{{ route($route) }}" @class(['tab-link', 'tab-link-active' => request()->routeIs(...$patterns)])
           @if (request()->routeIs(...$patterns)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
