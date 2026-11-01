@extends('layouts.auth')
@section('title', 'دخول إدارة المنصّة')
@section('brand', 'وهج العراق')
@section('brand-sub', 'إدارة المنصّة')

@section('content')
<div class="head">
    <h1 id="login-title">تسجيل الدخول</h1>
    <p @if (session('relogin')) role="status" @endif>{{ session('relogin') ?? 'لمدراء المنصّة فقط' }}</p>
</div>

<form method="POST" action="{{ route('admin.login') }}" aria-labelledby="login-title">
    @csrf
    @include('auth.partials.fields')

    <div class="options">
        <label class="remember">
            <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
            <span>تذكّرني</span>
        </label>
    </div>

    <button class="submit" type="submit">دخول <span aria-hidden="true">←</span></button>
</form>

<p class="secure">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg>
    دخولٌ آمن إلى لوحة المنصّة
</p>
@endsection
