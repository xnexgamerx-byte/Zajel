@props(['except' => []])
{{-- ما ألزمته الشركة من الحقول الاختياريّة (docs/plan/38): يُقال قبل الحفظ لا بعده --}}
@php
    $fields = array_diff(\App\Support\ShipmentFields::required(), (array) $except);
@endphp
@if ($fields)
    <p {{ $attributes->merge(['class' => 'rounded-lg bg-info-50 px-3 py-2 text-xs text-info-700 ring-1 ring-info-200']) }}>
        تُلزِم الشركة أيضاً بـ:
        {{ collect($fields)->map(fn ($field) => \App\Support\ShipmentFields::CHOOSABLE[$field])->implode('، ') }}.
    </p>
@endif
