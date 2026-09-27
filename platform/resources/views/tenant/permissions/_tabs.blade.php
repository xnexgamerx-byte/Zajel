{{-- الصلاحيات ثلاث خانات كما في المعتاد: من يحمل ماذا، والمراتب، والاستثنائية فوقها --}}
<nav class="tab-nav mb-4" aria-label="الصلاحيات">
    @foreach ([
        ['permissions.index', 'الموظّفون ومراتبهم', ['permissions.index']],
        ['permissions.ranks.index', 'المراتب', ['permissions.ranks.*']],
        ['permissions.grants.index', 'صلاحيات استثنائية', ['permissions.grants.*']],
    ] as [$route, $label, $patterns])
        <a href="{{ route($route) }}" @class(['tab-link', 'tab-link-active' => request()->routeIs(...$patterns)])
           @if (request()->routeIs(...$patterns)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
