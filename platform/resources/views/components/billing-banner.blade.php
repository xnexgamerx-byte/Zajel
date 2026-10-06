{{--
  ما على الشركة لإدارة المنصّة، أعلى كل صفحةٍ لمن يدفع عنها (docs/plan/36): متأخّرة ومتى يتوقّف
  النظام، أو فاتورةٌ صدرت ولم تُسدَّد، أو تجربةٌ أو اشتراكٌ ينتهي ولا يتجدّد. أهمّها وحده.
--}}
@php
    $dues = \App\Support\Billing\Dues::for($company);
    $overdue = $dues->oldestOverdue();
    $suspendsOn = $dues->suspendsOn();
    $next = $dues->nextDue();
    $ending = $dues->ending();
@endphp

@if ($company->isHeldForBilling())
    <div class="alert alert-bad mb-5" role="alert">
        <x-icon name="lock" class="size-5 shrink-0"/>
        <span class="font-medium">نظام شركتك متوقّف لتأخّر السداد — يعود وحده حين تُسجَّل الدفعة.</span>
        <a href="{{ route('billing') }}" class="btn-ghost ms-auto py-1">اشتراك الشركة وفواتيرها</a>
    </div>
@elseif ($overdue)
    <div class="alert alert-bad mb-5" role="alert">
        <x-icon name="alert" class="size-5 shrink-0"/>
        <span class="font-medium">
            فاتورة المنصّة <span class="num">{{ $overdue->number }}</span> متأخّرة منذ {{ \App\Support\Arabic::days(max(1, $dues->daysLate())) }}:
            عليكم <span class="num">{{ number_format($dues->balance()) }}</span> د.ع.
            @if ($suspendsOn)
                يتوقّف النظام في <span class="num">{{ $suspendsOn->format('Y-m-d') }}</span> إن لم تُسدَّد.
            @endif
        </span>
        <a href="{{ route('billing') }}" class="btn-ghost ms-auto py-1">ادفع</a>
    </div>
@elseif ($next)
    <div class="alert alert-info mb-5" role="status">
        <x-icon name="invoice" class="size-5 shrink-0"/>
        <span class="font-medium">
            صدرت فاتورة المنصّة <span class="num">{{ $next->number }}</span> بـ <span class="num">{{ number_format($next->balanceDue()) }}</span> د.ع،
            تُستحقّ في <span class="num">{{ $next->due_at?->format('Y-m-d') }}</span>.
        </span>
        <a href="{{ route('billing') }}" class="btn-ghost ms-auto py-1">التفاصيل</a>
    </div>
@elseif ($ending)
    <div class="alert alert-warn mb-5" role="status">
        <x-icon name="clock" class="size-5 shrink-0"/>
        <span class="font-medium">
            @if ($ending['days'] < 0)
                {{ $ending['kind'] === 'trial' ? 'انتهت الفترة التجريبية' : 'انتهى الاشتراك' }} في <span class="num">{{ $ending['ends']->format('Y-m-d') }}</span> — راجع إدارة المنصّة.
            @else
                {{ ($ending['kind'] === 'trial' ? 'الفترة التجريبية تنتهي' : 'الاشتراك ينتهي ولا يتجدّد').' '.($ending['days'] === 0 ? 'اليوم' : 'بعد '.\App\Support\Arabic::days($ending['days'])) }}
                (<span class="num">{{ $ending['ends']->format('Y-m-d') }}</span>).
            @endif
        </span>
        <a href="{{ route('billing') }}" class="btn-ghost ms-auto py-1">التفاصيل</a>
    </div>
@endif
