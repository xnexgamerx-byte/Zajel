{{--
  التنقّل بين الصفحات بالعربية وبلغة نظام التصميم (aegov-pagination): السابق
  والتالي نصّاً بسهم، والأرقام دوائر، والحالية ذهبيّة ممتلئة.
  (عرض لارافيل الافتراضي يكتب «Showing 1 to 25 of 100 results» بالإنجليزية،
  وينقلب داكناً على أجهزةٍ وضعُها ليليّ.)
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="التنقّل بين الصفحات" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-ink-500">
            @if ($paginator->firstItem())
                عرض
                <span class="num font-medium text-aeblack-900">{{ $paginator->firstItem() }}</span>–<span class="num font-medium text-aeblack-900">{{ $paginator->lastItem() }}</span>
                من
                <span class="num font-medium text-aeblack-900">{{ number_format($paginator->total()) }}</span>
            @else
                <span class="num font-medium text-aeblack-900">{{ $paginator->count() }}</span>
                من
                <span class="num font-medium text-aeblack-900">{{ number_format($paginator->total()) }}</span>
            @endif
        </p>

        <div class="flex flex-wrap items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="inline-flex items-center gap-1.5 px-1 py-1 text-sm text-ink-400" aria-disabled="true">
                    <x-icon name="chevron" class="size-4 ltr:-scale-x-100"/>
                    السابق
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                   class="inline-flex items-center gap-1.5 px-1 py-1 text-sm font-medium text-aeblack-800 transition-colors hover:text-primary-500">
                    <x-icon name="chevron" class="size-4 ltr:-scale-x-100"/>
                    السابق
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-1 text-sm text-aeblack-300" aria-disabled="true">…</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page"
                                  class="num grid h-8 min-w-8 place-items-center rounded-full bg-primary-600 px-1.5 text-sm font-medium text-white">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" aria-label="الصفحة {{ $page }}"
                               class="num grid h-8 min-w-8 place-items-center rounded-full px-1.5 text-sm text-aeblack-800 transition-colors hover:bg-primary-50 hover:text-primary-800">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                   class="inline-flex items-center gap-1.5 px-1 py-1 text-sm font-medium text-aeblack-800 transition-colors hover:text-primary-500">
                    التالي
                    <x-icon name="chevron" class="size-4 rtl:-scale-x-100"/>
                </a>
            @else
                <span class="inline-flex items-center gap-1.5 px-1 py-1 text-sm text-ink-400" aria-disabled="true">
                    التالي
                    <x-icon name="chevron" class="size-4 rtl:-scale-x-100"/>
                </span>
            @endif
        </div>
    </nav>
@endif
