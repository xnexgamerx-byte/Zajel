@extends('layouts.auth')
@section('title', 'تسجيل الدخول')
@section('brand', 'نظام إدارة الشحنات')
@section('brand-sub', 'نظام إدارة الشحنات')

@section('content')
<div class="head">
    <h1 id="login-title">تسجيل الدخول</h1>
    {{-- خرج من حسابه وصفحته مفتوحة (ExpiredForm): يعود إليها بعد الدخول --}}
    <p @if (session('relogin')) role="status" @endif>{{ session('relogin') ?? 'أهلاً بك، سجّل دخولك للمتابعة' }}</p>
</div>

<form method="POST" action="{{ route('login') }}" aria-labelledby="login-title">
    @csrf
    @include('auth.partials.fields')

    <div class="options">
        <label class="remember">
            <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
            <span>تذكّرني</span>
        </label>
        <a class="forgot" href="#forgot">نسيت كلمة المرور؟</a>
    </div>

    <button class="submit" type="submit">دخول <span aria-hidden="true">←</span></button>
</form>

<p class="forgot-note" id="forgot">
    لا تُستعاد كلمة المرور من هنا: اطلب من إدارة الشركة أن تضع لك كلمةً جديدة من حسابك في النظام.
</p>

<p class="secure">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg>
    دخولٌ آمن ومشفّر
</p>
@endsection
