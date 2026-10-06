@extends('layouts.platform')
@section('title', 'إعدادات المنصّة')

@section('content')
<div class="mb-5">
    <h1 class="page-title">إعدادات المنصّة</h1>
    <p class="mt-1 text-sm text-ink-500">كيف تدفع الشركات، ومتى يتوقّف نظام شركةٍ تأخّرت، ومتى تُنبَّه قبل انتهاء اشتراكها.</p>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <form method="POST" action="{{ route('admin.settings.update') }}" class="card space-y-5 p-5 lg:col-span-2">
        @csrf
        @method('PUT')

        <div>
            <label class="field-label" for="payment_methods">طرق الدفع</label>
            <textarea id="payment_methods" name="payment_methods" rows="5" maxlength="2000" class="field-input"
                      placeholder="زين كاش: 07xxxxxxxxx — باسم …&#10;حوالة آسيا: …&#10;حساب مصرفي: …">{{ old('payment_methods', $methods) }}</textarea>
            <p class="field-hint">سطرٌ لكل طريقة. تراها كل شركة في «اشتراك الشركة وفواتيرها»، وتحتها «دفعتُ» تُبلغ به عن دفعتها.</p>
            @error('payment_methods') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="field-label" for="grace_days">مهلة الإيقاف التلقائي (أيام)</label>
                <input id="grace_days" name="grace_days" inputmode="numeric" class="field-input num" placeholder="فارغ — لا إيقاف تلقائي"
                       value="{{ old('grace_days', $grace) }}">
                <p class="field-hint">
                    بعد موعد الفاتورة بهذه الأيام بلا سداد يتوقّف نظام الشركة ليلاً، إلّا صفحة فواتيرها، ويعود وحده حين تُسجَّل
                    الدفعة. وتراه الشركة قبلها في تنبيهٍ بتاريخه. فارغاً: لا إيقاف.
                </p>
                @error('grace_days') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="reminder_days">التنبيه قبل انتهاء الاشتراك (أيام)</label>
                <input id="reminder_days" name="reminder_days" inputmode="numeric" class="field-input num" required
                       value="{{ old('reminder_days', $reminder) }}">
                <p class="field-hint">للفترة التجريبية وللاشتراك الذي لا يتجدّد تلقائياً.</p>
                @error('reminder_days') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <button type="submit" class="btn-primary">احفظ</button>
    </form>

    <section class="card p-5">
        <h2 class="card-title">متأخّرةٌ الآن</h2>
        <p class="card-hint">
            @if ($grace)
                بمهلة <span class="num">{{ $grace }}</span> يوماً يتوقّف ليلاً مَن زاد تأخّره عليها — إلّا المعفاة.
            @else
                لا مهلة مضبوطة: لا تتوقّف شركةٌ بالتأخّر.
            @endif
        </p>
        @forelse ($late as $row)
            <a href="{{ route('admin.subscriptions.show', $row['company']) }}" class="-mx-2 mt-1 flex flex-wrap items-center gap-x-2 rounded-lg px-2 py-2 text-sm hover:bg-ink-50">
                <span class="font-semibold">{{ $row['company']->name }}</span>
                <span class="text-xs text-ink-500">منذ {{ \App\Support\Arabic::days(max(1, $row['days'])) }}</span>
                <span class="num ms-auto font-semibold" dir="ltr">{{ number_format($row['balance']) }}</span>
                <span class="w-full text-xs">
                    @if ($row['company']->isHeldForBilling())
                        <span class="text-bad-700">متوقّفة لتأخّر السداد</span>
                    @elseif ($row['company']->billing_exempt)
                        <span class="text-ink-500">معفاة من الإيقاف</span>
                    @elseif ($grace && $row['days'] >= $grace)
                        <span class="text-bad-700">تتوقّف الليلة</span>
                    @elseif ($grace)
                        <span class="text-warn-700">تتوقّف بعد {{ \App\Support\Arabic::days($grace - $row['days']) }}</span>
                    @endif
                </span>
            </a>
        @empty
            <p class="mt-3 text-sm text-ink-500">لا شركة متأخّرة.</p>
        @endforelse
    </section>
</div>
@endsection
