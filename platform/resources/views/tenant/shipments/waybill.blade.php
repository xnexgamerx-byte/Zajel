@extends('layouts.app')
@section('title', 'شحنة من وصلٍ مطبوع')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">شحنة من وصلٍ مطبوع</h1>
        <p class="mt-1 text-sm text-ink-500">
            امسح الوصل الذي كتب عليه التاجر بيده — باركوده أو رمز QR — فيُفتح نموذج الشحنة وعليه رقمه وتاجره.
            وبعد الحفظ تعود هنا للوصل التالي.
        </p>
    </div>
    <a href="{{ route('waybill-books.index') }}" class="btn-ghost">دفاتر الوصولات المطبوعة</a>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <form method="GET" action="{{ route('shipments.waybill') }}" class="card space-y-3 p-5 lg:col-span-2">
        <label class="field-label" for="waybill-code">رقم الوصل المطبوع</label>
        <div class="flex gap-2">
            <input id="waybill-code" name="code" value="{{ $code }}" class="field-input text-left text-lg" dir="ltr"
                   autocomplete="off" autofocus inputmode="numeric" placeholder="امسح أو اكتب ثم Enter" required>
            <button type="submit" class="btn-primary">افتح</button>
        </div>
        @if ($problem)
            <p class="field-error" role="alert">
                {{ $problem }}
                @if ($used)
                    <a href="{{ route('shipments.show', $used) }}" class="font-semibold underline">افتح الشحنة</a>
                @endif
            </p>
        @endif
    </form>

    <aside class="card space-y-2 p-5 text-sm text-ink-600">
        <h2 class="card-title">كيف يعمل</h2>
        <ol class="list-decimal space-y-1 ps-5">
            <li>يطبع التاجر وصولاته من بوابته («وصولات للطباعة») بمقاس طابعته، أو تطبعها له الشركة من «دفاتر الوصولات المطبوعة».</li>
            <li>يكتب على كل وصلٍ بيده الزبون وهاتفه وعنوانه والمبلغ، ويلصقه على الطرد.</li>
            <li>يُمسح الوصل هنا وتُدخَل بياناته، فتصير شحنةً يُمسح رقمها المطبوع في كل مكان: الاستلام والإسناد وتطبيق المندوب.</li>
        </ol>
    </aside>
</div>
@endsection
