@extends('layouts.auth')
@section('title', 'تسجيل الدخول')
@section('brand', 'نظام إدارة الشحنات')

@section('content')
<header class="brand">
    <h1>نظام إدارة الشحنات</h1>
    <p>يرجى تسجيل الدخول للمتابعة</p>
</header>

<section class="card" aria-labelledby="login-title">
    <div class="card-head">
        <h2 id="login-title">تسجيل الدخول</h2>
        <p>أهلاً بك، سجّل دخولك للمتابعة</p>
    </div>

    <form method="POST" action="{{ route('login') }}">
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

    <p class="foot">دخول آمن إلى <span>نظام إدارة الشحنات</span></p>
</section>
@endsection
