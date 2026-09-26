@extends('layouts.platform')
@section('title', 'تعديل '.$company->name)

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">تعديل بيانات {{ $company->name }}</h1>
        <p class="mt-1 text-sm text-ink-500">اسمها وطرق الوصول إليها ولونها. كل تعديلٍ يُسجَّل في سجلّ التدقيق.</p>
    </div>
    <a href="{{ route('admin.companies.show', $company) }}" class="btn-ghost">رجوع</a>
</div>

<form method="POST" action="{{ route('admin.companies.update', $company) }}" class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    @csrf
    @method('PUT')

    <section class="card p-5 lg:col-span-2">
        <h2 class="mb-4 text-sm font-bold">الشركة</h2>
        @include('platform.companies._fields')
    </section>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-3 text-sm font-bold">وما ليس هنا</h2>
            <ul class="space-y-2.5 text-xs leading-relaxed text-ink-600">
                <li>
                    <span class="font-semibold text-ink-800">اشتراكها وفواتيرها:</span>
                    من <a href="{{ route('admin.subscriptions.show', $company) }}"
                          class="font-semibold text-[var(--brand)] hover:underline">«الاشتراكات»</a>.
                </li>
                <li>
                    <span class="font-semibold text-ink-800">حساب صاحبها وكلمة مروره:</span>
                    «ادخل نظامها» ثم «المستخدمون».
                </li>
                <li>
                    <span class="font-semibold text-ink-800">أسعار التوصيل:</span>
                    شأنها مع تجّارها، تضبطها من «التسعيرات» في نظامها.
                </li>
            </ul>
        </section>

        <button type="submit" class="btn-primary w-full">احفظ التعديلات</button>
    </div>
</form>
@endsection
