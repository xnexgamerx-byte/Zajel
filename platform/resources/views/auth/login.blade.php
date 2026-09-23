@extends('layouts.app')
@section('title', 'تسجيل الدخول')

@section('content')
<div class="mx-auto mt-6 w-full max-w-md sm:mt-14">
    <div class="mb-8 text-center">
        <div class="mx-auto mb-5 grid size-16 place-items-center rounded-[22px] border border-ink-900 text-2xl font-bold text-white"
             style="background: {{ $company->primary_color }}">ز</div>
        <h1 class="page-title">{{ $company->name }}</h1>
        <p class="mt-1 text-sm text-ink-500">نظام إدارة الشحنات</p>
    </div>

    <form method="POST" action="{{ route('login') }}" class="card space-y-5 rounded-[28px] p-6 sm:p-8">
        @csrf

        <div>
            <label class="field-label" for="phone">رقم الهاتف</label>
            <input id="phone" name="phone" type="tel" inputmode="numeric" dir="ltr"
                   value="{{ old('phone') }}" placeholder="07xxxxxxxxx"
                   class="field-input text-left" required autofocus>
            @error('phone') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="field-label" for="password">كلمة المرور</label>
            <input id="password" name="password" type="password" class="field-input" required>
            @error('password') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-ink-600">
            <input type="checkbox" name="remember" value="1"
                   class="rounded">
            تذكّرني
        </label>

        <button type="submit" class="btn-primary w-full py-3 text-base">دخول</button>
    </form>
</div>
@endsection
