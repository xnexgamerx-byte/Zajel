{{--
  التنقّل بين الصفحات بالعربية وبلغة التصميم: حبوبٌ مُحاطة، والحالية داكنة.
  (عرض لارافيل الافتراضي يكتب «Showing 1 to 25 of 100 results» بالإنجليزية،
  وينقلب داكناً على أجهزةٍ وضعُها ليليّ.)
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="التنقّل بين الصفحات" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-ink-500">
            @if ($paginator->firstItem())
                عرض
                <span class="num font-medium text-ink-900">{{ $paginator->firstItem() }}</span>–<span class="num font-medium text-ink-900">{{ $paginator->lastItem() }}</span>
                من
                <span class="num font-medium text-ink-900">{{ number_format($paginator->total()) }}</span>
            @else
                <span class="num font-medium text-ink-900">{{ $paginator->count() }}</span>
                من
                <span class="num font-medium text-ink-900">{{ number_format($paginator->total()) }}</span>
            @endif
        </p>

        <div class="flex flex-wrap items-center gap-1.5">
            @if ($paginator->onFirstPage())
                <span class="icon-btn size-9 cursor-not-allowed opacity-40" aria-disabled="true" aria-label="السابق">
                    <x-icon name="chevron" class="size-4 ltr:-scale-x-100"/>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="icon-btn size-9" aria-label="السابق">
                    <x-icon name="chevron" class="size-4 ltr:-scale-x-100"/>
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-1 text-sm text-ink-400" aria-disabled="true">…</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page"
                                  class="num grid h-9 min-w-9 place-items-center rounded-full border border-ink-900 bg-ink-900 px-2 text-sm font-medium text-white">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" aria-label="الصفحة {{ $page }}"
                               class="num grid h-9 min-w-9 place-items-center rounded-full border border-ink-900 bg-white px-2 text-sm text-ink-900 transition hover:bg-ink-100">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="icon-btn size-9" aria-label="التالي">
                    <x-icon name="chevron" class="size-4 rtl:-scale-x-100"/>
                </a>
            @else
                <span class="icon-btn size-9 cursor-not-allowed opacity-40" aria-disabled="true" aria-label="التالي">
                    <x-icon name="chevron" class="size-4 rtl:-scale-x-100"/>
                </span>
            @endif
        </div>
    </nav>
@endif
