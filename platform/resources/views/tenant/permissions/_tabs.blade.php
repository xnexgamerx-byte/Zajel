{{--
  الصلاحيات بثلاث خطوات واضحة (docs/plan/38): المرتبة مجموعة صلاحيات باسم، تُعطى للموظّف،
  وما يحتاجه موظّفٌ وحده فوق مرتبته صلاحيةٌ إضافية له. والكلمات كما يقولها صاحب الشركة.
--}}
<nav class="tab-nav mb-3" aria-label="الصلاحيات">
    @foreach ([
        ['permissions.index', '١ · الموظّفون', ['permissions.index']],
        ['permissions.ranks.index', '٢ · المراتب', ['permissions.ranks.*']],
        ['permissions.grants.index', '٣ · صلاحية إضافية لموظّف', ['permissions.grants.*']],
    ] as [$route, $label, $patterns])
        <a href="{{ route($route) }}" @class(['tab-link', 'tab-link-active' => request()->routeIs(...$patterns)])
           @if (request()->routeIs(...$patterns)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
<p class="mb-4 rounded-xl bg-ink-50 px-4 py-2.5 text-xs leading-6 text-ink-600">
    <span class="font-semibold text-ink-800">كيف تعمل؟</span>
    المرتبة اسمٌ لمجموعة صلاحيات (مثل «محاسب» أو «كول سنتر»). أعطِ كل موظّفٍ مرتبته، وكل تعديلٍ على المرتبة يسري على كل من يحملها.
    وما يحتاجه موظّفٌ واحد فوق مرتبته يُعطى له وحده من «صلاحية إضافية». وصاحب الشركة يملك كل شيء دائماً.
</p>
