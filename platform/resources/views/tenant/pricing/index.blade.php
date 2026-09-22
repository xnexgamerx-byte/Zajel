@extends('layouts.app')
@section('title', 'التسعيرات')

@section('content')
<div class="mb-5">
    <h1 class="text-xl font-bold">التسعيرات</h1>
    <p class="mt-1 text-sm text-ink-500">
        أجرة التوصيل حسب المحافظة. كل شحنة جديدة تُسعَّر من تسعيرة تاجرها،
        وما لم تُحدَّد له فمن الافتراضية.
    </p>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-3 lg:col-span-2">
        @foreach ($lists as $list)
            <a href="{{ route('pricing.edit', $list) }}"
               class="card flex flex-wrap items-center gap-4 p-5 hover:bg-ink-50">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="text-base font-bold">{{ $list->name }}</span>
                        @if ($list->is_default)
                            <span class="rounded-full bg-[var(--brand-soft)] px-2 py-0.5 text-xs font-semibold text-[var(--brand)]">
                                افتراضية
                            </span>
                        @endif
                        @unless ($list->is_active)
                            <span class="rounded-full bg-ink-100 px-2 py-0.5 text-xs text-ink-600">معطّلة</span>
                        @endunless
                    </div>
                    <div class="mt-1 text-sm text-ink-500">
                        {{ $list->rules_count }} قاعدة
                        · {{ number_format($usage[$list->id] ?? 0) }} تاجر
                        @if ($list->is_default)
                            <span class="text-ink-400">(+ {{ number_format($defaultUsers) }} بلا تسعيرة خاصة)</span>
                        @endif
                    </div>
                </div>
                <span class="text-sm font-semibold text-[var(--brand)]">تحرير</span>
            </a>
        @endforeach

        @if ($lists->isEmpty())
            <div class="card p-16 text-center text-ink-500">لا تسعيرات بعد.</div>
        @endif
    </div>

    <div>
        <form method="POST" action="{{ route('pricing.store') }}" class="card space-y-4 p-5">
            @csrf
            <h2 class="text-sm font-bold">تسعيرة جديدة</h2>

            <div>
                <label class="field-label" for="name">الاسم</label>
                <input id="name" name="name" class="field-input" required
                       placeholder="مثال: تسعيرة التجّار الكبار" value="{{ old('name') }}">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label" for="copy_from">انسخ من</label>
                <select id="copy_from" name="copy_from" class="field-input">
                    <option value="">ابدأ فارغة</option>
                    @foreach ($lists as $list)
                        <option value="{{ $list->id }}">{{ $list->name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-ink-500">النسخ ثم التعديل أسرع من إدخال 18 صفاً.</p>
            </div>

            <button type="submit" class="btn-primary w-full">أنشئ</button>
        </form>
    </div>
</div>
@endsection
