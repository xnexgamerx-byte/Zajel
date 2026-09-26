@extends('layouts.app')
@section('title', 'بيانات الشركة')

@section('content')
<div class="mb-5">
    <h1 class="page-title">بيانات الشركة</h1>
    <p class="mt-1 text-sm text-ink-500">ما يراه تجّارك ومناديبك: كيف يصلونك، ولون واجهتك.</p>
</div>

<form method="POST" action="{{ route('settings.company.update') }}" class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    @csrf
    @method('PUT')

    <section class="card space-y-4 p-5 lg:col-span-2">
        <div>
            <span class="field-label">الاسم</span>
            <p class="font-semibold">{{ $company->name }}</p>
            {{-- الاسم والنطاق هويّة الاشتراك: تغييرهما من المنصّة لا من هنا --}}
            <p class="text-xs text-ink-500">الاسم والرابط <span class="num">{{ $company->slug }}.{{ config('zajel.tenant_domain') }}</span> يُغيَّران بطلبٍ إلى إدارة المنصّة.</p>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="field-label" for="phone">هاتف الشركة</label>
                <input id="phone" name="phone" class="field-input num" inputmode="tel" placeholder="07xxxxxxxxx"
                       value="{{ old('phone', $company->phone) }}">
            </div>
            <div>
                <label class="field-label" for="email">البريد</label>
                <input id="email" name="email" type="email" class="field-input num" value="{{ old('email', $company->email) }}">
            </div>
        </div>

        <div class="rounded-xl border border-ink-200 p-4">
            <h2 class="card-title">واتساب الدعم</h2>
            <p class="card-hint mb-3">يظهر زرًّا في بوّابة التاجر وتطبيق المندوب يفتح محادثة واتساب مع هذا الرقم.</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="support_whatsapp">الرقم</label>
                    <input id="support_whatsapp" name="support_whatsapp" class="field-input num" inputmode="tel" placeholder="07xxxxxxxxx"
                           value="{{ old('support_whatsapp', $company->setting('support.whatsapp')) }}">
                </div>
                <div>
                    <label class="field-label" for="support_hours">ساعات الدعم (اختياري)</label>
                    <input id="support_hours" name="support_hours" class="field-input" maxlength="120"
                           placeholder="من السبت إلى الخميس، ٩ صباحاً – ٥ مساءً"
                           value="{{ old('support_hours', $company->setting('support.hours')) }}">
                </div>
            </div>
            @if ($link = \App\Support\Phone::whatsappUrl($company->setting('support.whatsapp')))
                <a href="{{ $link }}" target="_blank" rel="noopener" class="mt-3 inline-block text-sm text-[var(--brand)] hover:underline">جرّب الرابط ←</a>
            @endif
        </div>
    </section>

    <section class="card space-y-4 p-5">
        <div>
            <label class="field-label" for="primary_color">لون الشعار</label>
            <div class="flex items-center gap-3">
                <input id="primary_color" name="primary_color" type="color" class="h-10 w-16 cursor-pointer rounded-lg border border-ink-200"
                       value="{{ old('primary_color', $company->primary_color) }}"
                       oninput="document.documentElement.style.setProperty('--company', this.value)">
                <span class="num text-sm text-ink-600">{{ old('primary_color', $company->primary_color) }}</span>
            </div>
            <p class="mt-1 text-xs text-ink-500">مربّع الشعار في أعلى كل صفحة: النظام، وبوّابة التاجر، وتطبيق المندوب، وصفحة التتبّع. يُعاين هنا قبل الحفظ.</p>
        </div>

        <button type="submit" class="btn-primary w-full">احفظ</button>
    </section>
</form>
@endsection
