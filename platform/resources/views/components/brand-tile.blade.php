@props(['company', 'size' => ''])

{{--
| شعار الشركة في رأس الشاشة (docs/plan/45): لوغوها إن رُفع، وإلّا فلمن يملك إعداداتها «ارفع اللوغو هنا»،
| ولغيره الحرف الأوّل من اسمها بلونها.
--}}
@php
    $logo = $company->logoUrl();
    $canUpload = ! $logo && auth()->check() && auth()->user()->can('settings.company');
@endphp
@if ($logo)
    <span {{ $attributes->merge(['class' => "brand-tile brand-tile-logo {$size}"]) }}>
        <img src="{{ $logo }}" alt="{{ $company->name }}" class="size-full object-contain">
    </span>
@elseif ($canUpload)
    {{-- حجم خطّ الحرف لا يصلح للعبارة: تبقى صغيرةً داخل الخانة --}}
    <span {{ $attributes->merge(['class' => 'brand-tile brand-tile-empty '.preg_replace('/\btext-\S+/', '', $size)]) }} title="ارفع لوغو الشركة">ارفع اللوغو هنا</span>
@else
    <span {{ $attributes->merge(['class' => "brand-tile {$size}"]) }}>{{ $company->initial() }}</span>
@endif
