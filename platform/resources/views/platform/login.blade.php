@extends('layouts.auth')
@section('title', 'دخول إدارة المنصّة')
@section('brand', 'وهج العراق')

@section('content')
<header class="brand">
    <div class="brand-mark">و</div>
    <h1>وهج العراق</h1>
    <p>إدارة المنصّة</p>
</header>

<section class="card" aria-labelledby="login-title">
    <div class="card-head">
        <h2 id="login-title">تسجيل الدخول</h2>
        <p @if (session('relogin')) role="status" @endif>{{ session('relogin') ?? 'لمدراء المنصّة فقط' }}</p>
    </div>

    <form method="POST" action="{{ route('admin.login') }}">
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

    <p class="foot">دخول آمن إلى <span>لوحة المنصّة</span></p>
</section>
@endsection
