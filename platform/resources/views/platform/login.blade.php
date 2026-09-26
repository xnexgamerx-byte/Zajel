@extends('layouts.platform')
@section('title', 'دخول إدارة المنصّة')

@section('content')
<div class="mx-auto mt-6 w-full max-w-md sm:mt-14">
    <div class="mb-8 text-center">
        <div class="brand-tile mx-auto mb-5 size-16 rounded-xl text-2xl">و</div>
        <h1 class="page-title">إدارة منصّة وهج العراق</h1>
        <p class="mt-1 text-sm text-ink-500">دخول مدراء المنصّة فقط</p>
    </div>

    <form method="POST" action="{{ route('admin.login') }}" class="card space-y-5 p-6 sm:p-8">
        @csrf
        <div>
            <label class="field-label" for="phone">رقم الهاتف</label>
            <input id="phone" name="phone" type="tel" dir="ltr" inputmode="numeric"
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
        <button type="submit" class="btn-primary w-full py-2.5 text-base">دخول</button>
    </form>
</div>
@endsection
