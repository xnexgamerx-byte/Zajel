@extends('layouts.platform')
@section('title', 'دخول إدارة المنصّة')

@section('content')
<div class="mx-auto mt-14 w-full max-w-sm">
    <div class="mb-7 text-center">
        <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-plat-600 text-2xl font-black text-white">ز</div>
        <h1 class="text-xl font-bold">إدارة منصّة زاجل</h1>
        <p class="mt-1 text-sm text-ink-500">دخول مدراء المنصّة فقط</p>
    </div>

    <form method="POST" action="{{ route('admin.login') }}" class="card space-y-4 p-6">
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
                   class="rounded border-ink-300 text-plat-600 focus:ring-plat-600/20">
            تذكّرني
        </label>
        <button type="submit" class="btn-primary w-full">دخول</button>
    </form>
</div>
@endsection
