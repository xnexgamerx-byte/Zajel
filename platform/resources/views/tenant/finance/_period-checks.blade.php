{{-- فحوص ما قبل الإقفال: ما نجح، وما فشل باستثنائه إن وُثِّق --}}
<ul class="divide-y divide-ink-100">
    @foreach ($checks as $c)
        <li class="flex flex-wrap items-start gap-3 px-5 py-3 text-sm">
            <span @class(['chip shrink-0', 'chip-ok' => $c['ok'], 'chip-bad' => ! $c['ok'] && empty($exceptions[$c['key']]), 'chip-warn' => ! $c['ok'] && ! empty($exceptions[$c['key']])])>
                {{ $c['ok'] ? 'ناجح' : (empty($exceptions[$c['key']]) ? 'لم ينجح' : 'باستثناء') }}
            </span>
            <div class="min-w-0 flex-1">
                <div class="font-semibold">{{ $c['label'] }}</div>
                <div class="text-xs text-ink-500">{{ $c['detail'] }}</div>
                @if (! empty($exceptions[$c['key']]))<div class="text-xs text-warn-700">الاستثناء: {{ $exceptions[$c['key']] }}</div>@endif
                @if (! empty($exceptionInputs) && ! $c['ok'] && ! in_array($c['key'], \App\Services\Finance\PeriodClose::BLOCKING, true) && $c['key'] !== 'initialized')
                    <input name="exceptions[{{ $c['key'] }}]" value="{{ old('exceptions.'.$c['key']) }}" maxlength="255" form="prepare-form"
                           class="field-input mt-2 w-full max-w-xl py-1 text-xs" placeholder="سبب تجاوز هذا الفحص (يُحفظ مع الإقفال)" aria-label="سبب الاستثناء: {{ $c['label'] }}">
                @endif
            </div>
        </li>
    @endforeach
</ul>
