@props(['status'])

@php
    // اللون تعزيز؛ النصّ هو القناة الأساسية، والنقطة قناة ثالثة.
    $tone = match ($status->color()) {
        'green' => 'chip-ok',
        'red'   => 'chip-bad',
        'amber' => 'chip-warn',
        'blue'  => 'chip-info',
        default => 'chip-mute',
    };
@endphp

<span {{ $attributes->merge(['class' => "chip $tone"]) }}>{{ $status->label() }}</span>
