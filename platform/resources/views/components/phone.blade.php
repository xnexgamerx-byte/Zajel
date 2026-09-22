@props(['number', 'name' => null])

@php
    $digits = preg_replace('/\D+/', '', (string) $number);
    // آخر ثلاثة تكفي للتمييز بين زبونين، ولا تكفي للاتصال
    $masked = $digits === '' ? '—' : str_repeat('•', max(0, strlen($digits) - 3)).substr($digits, -3);
@endphp

@if ($digits === '')
    <span class="text-ink-400">—</span>
@else
    {{--
      رقم الزبون بيد الشركة أمانةً لا ملكاً. الكشف واحداً واحداً يجعل
      نسخ قائمة زبائن كاملة عملاً مقصوداً مئة مرّة لا تحديداً بالفأرة.
    --}}
    <span class="inline-flex items-center gap-1.5" data-phone>
        <span class="num" data-phone-value data-real="{{ $digits }}" data-masked="{{ $masked }}">{{ $masked }}</span>
        <button type="button" class="rounded p-0.5 text-ink-400 transition hover:text-[var(--brand)]"
                aria-label="اكشف رقم {{ $name ?? 'الزبون' }}"
                onclick="revealPhone(this)">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
            </svg>
        </button>
    </span>
@endif
