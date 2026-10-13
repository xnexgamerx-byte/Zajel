@extends('layouts.app')
@section('title', 'الشركاء ومفاتيح الربط')

@section('content')
<div class="mb-5">
    <h1 class="page-title">الشركاء الذين نسلّم لهم الطرود</h1>
    <p class="mt-1 text-sm text-ink-500">
        الوسيط الذي يوزّع في المحافظات: نسلّمه الطرود، ونرى أين صارت، ويحدّث حالاتها بمفتاح ربطه.
    </p>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <section class="card overflow-hidden lg:col-span-2">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>الشريك</th>
                        <th>بيده الآن</th>
                        <th>مفاتيح ربط فعّالة</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($partners as $partner)
                        <tr>
                            <td>
                                <a href="{{ route('partners.show', $partner) }}" class="font-semibold text-[var(--brand)] hover:underline">{{ $partner->name }}</a>
                                @unless ($partner->isActive())<span class="chip chip-warn ms-1">موقوف</span>@endunless
                            </td>
                            <td class="num font-semibold">{{ number_format($partner->held_count) }}</td>
                            <td class="num">{{ number_format($partner->active_keys_count) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-10 text-center text-ink-500">لا شريك بعد. أضِف الوسيط من النموذج بجانب.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card h-fit p-5">
        <h2 class="card-title mb-3">شريكٌ جديد</h2>
        <form method="POST" action="{{ route('partners.store') }}" class="space-y-3">
            @csrf
            <label class="block">
                <span class="field-label">اسم الشركة أو الوسيط</span>
                <input name="name" value="{{ old('name') }}" maxlength="120" required class="field-input" placeholder="مثلاً: شركة الوسيط للتوصيل">
            </label>
            <button class="btn-primary w-full">أضِف الشريك</button>
        </form>
    </section>
</div>
@endsection
