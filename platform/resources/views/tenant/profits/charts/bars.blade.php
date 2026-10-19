{{-- أعمدةٌ أفقية مرتّبة بقيمها، والسالب أحمر وبعلامته؛ كل سطرٍ رابط --}}
@php($max = max(1, (int) collect($rows)->max(fn ($r) => abs((int) $r['value']))))
<ul class="space-y-2 text-sm">
    @forelse ($rows as $row)
        <li>
            <a @if ($row['href']) href="{{ $row['href'] }}" @endif class="group block">
                <div class="mb-1 flex items-center justify-between gap-2">
                    <span class="truncate group-hover:underline">{{ $row['label'] }}</span>
                    <span @class(['num font-semibold', 'text-bad-700' => $row['value'] < 0, 'text-profit-700' => $row['value'] >= 0])>{{ $row['value'] < 0 ? '−' : '' }}{{ number_format(abs($row['value'])) }}</span>
                </div>
                <div class="h-2.5 rounded-full bg-ink-100">
                    <div class="h-2.5 rounded-full" style="width: {{ round(abs($row['value']) / $max * 100, 1) }}%; background: var({{ $row['value'] < 0 ? '--color-bad-700' : '--color-profit-500' }})"></div>
                </div>
            </a>
        </li>
    @empty
        <li class="py-6 text-center text-ink-500">لا أرقام في هذه المدّة.</li>
    @endforelse
</ul>
