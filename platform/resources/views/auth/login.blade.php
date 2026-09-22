@extends('layouts.app')
@section('title', 'تسجيل الدخول')

@section('content')
<div class="mx-auto mt-10 w-full max-w-sm">
    <div class="mb-6 text-center">
        <div class="mx-auto mb-3 grid h-14 w-14 place-items-center rounded-2xl text-2xl font-black text-white"
             style="background: {{ $company->primary_color }}">ز</div>
        <h1 class="text-xl font-bold">{{ $company->name }}</h1>
        <p class="mt-1 text-sm text-slate-500">نظام إدارة الشحنات</p>
    </div>

    <form method="POST" action="{{ route('login') }}" class="card space-y-4 p-6">
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

        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="remember" value="1"
                   class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            تذكّرني
        </label>

        <button type="submit" class="btn-primary w-full">دخول</button>
    </form>
</div>
@endsection
