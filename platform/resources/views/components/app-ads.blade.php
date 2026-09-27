@props(['audience'])

@php
    $ads = \App\Models\AppAd::shownTo($audience)->limit(6)->get(['id', 'title', 'link_url']);
@endphp

{{-- «إعلانات الصفحة الرئيسية بالتطبيق»: شريطٌ ينزلق أفقياً على الهاتف --}}
@if ($ads->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'flex snap-x gap-3 overflow-x-auto pb-1']) }}>
        @foreach ($ads as $ad)
            @php $tag = $ad->link_url ? 'a' : 'div'; @endphp
            <{{ $tag }} @if ($ad->link_url) href="{{ $ad->link_url }}" target="_blank" rel="noopener" @endif
                class="relative block w-72 shrink-0 snap-start overflow-hidden rounded-2xl bg-ink-100 sm:w-80">
                <img src="{{ route('app-ads.image', $ad) }}" alt="{{ $ad->title }}" class="h-36 w-full object-cover" loading="lazy">
                <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/60 to-transparent px-3 pt-6 pb-2 text-sm font-semibold text-white">{{ $ad->title }}</span>
            </{{ $tag }}>
        @endforeach
    </div>
@endif
