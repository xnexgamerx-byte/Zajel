@extends('layouts.platform')
@section('title', 'الميزات')

@section('content')
<div class="mb-5">
    <h1 class="page-title">الميزات</h1>
    <p class="mt-1 text-sm text-ink-500">
        ما يُفتح لكل شركةٍ وحدها ويُغلق، مجّاناً أو برسمٍ شهريّ على فاتورتها. والميزة الجديدة مطفأةٌ في كل الشركات
        حتى تفتحها لشركةٍ بعينها — من صفحة الميزة هنا، أو من «نظامها» في صفحة الشركة.
    </p>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>الميزة</th>
                    <th>مفتوحة في</th>
                    <th>برسم</th>
                    <th>تُدخل شهرياً</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @foreach ($rows as $row)
                    <tr class="hover:bg-ink-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.features.show', $row['feature']) }}" class="font-semibold text-[var(--brand)] hover:underline">
                                {{ $row['feature']->label() }}
                            </a>
                            @unless ($row['feature']->included())
                                <span class="chip chip-info ms-1">إضافة</span>
                            @endunless
                            <span class="mt-0.5 block max-w-xl text-xs text-ink-500">{{ $row['feature']->description() }}</span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="num font-semibold">{{ $row['enabled'] }}</span>
                            <span class="text-xs text-ink-500">من <span class="num">{{ $companies }}</span></span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap"><span class="num">{{ $row['paying'] }}</span></td>
                        <td class="px-4 py-3 whitespace-nowrap font-semibold" dir="ltr">{{ number_format($row['monthly']) }} د.ع</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
