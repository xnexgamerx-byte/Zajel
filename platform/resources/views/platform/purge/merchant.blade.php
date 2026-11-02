@extends('layouts.platform')
@section('title', 'حذف تاجر')

{{-- تاجرٌ واحدٌ وكلّ ما له (docs/plan/108): الأعداد، وما يمنع الحذف إن وُجد، والتأكيد برمزه وكلمة المرور --}}
@section('content')
<div class="mb-5">
    <a href="{{ route('admin.purge.index', ['company' => $company->id]) }}" class="text-sm font-semibold text-[var(--brand)] hover:underline">→ غيّر الاختيار</a>
    <h1 class="page-title mt-2">حذف التاجر {{ $merchant->business_name }} من {{ $company->name }}</h1>
    <p class="mt-1 text-sm text-ink-500">رمزه <span dir="ltr" class="font-mono">{{ $merchant->code }}</span>. هذا ما سيُحذف معه، ولا يُسترجع بعده. غيره من التجّار لا يُمسّ.</p>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <section class="card p-5 lg:col-span-2">
        <h2 class="card-title">ما سيُحذف</h2>
        <ul class="mt-4 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
            @foreach ($rows as [$label, $n])
                <li class="flex justify-between gap-3 rounded-lg px-3 py-2 ring-1 ring-bad-200"><span>{{ $label }}</span><b class="num">{{ number_format($n) }}</b></li>
            @endforeach
            <li class="flex justify-between gap-3 rounded-lg px-3 py-2 ring-1 ring-bad-200"><span>التاجر نفسه</span><b class="num">1</b></li>
        </ul>
        <p class="mt-3 text-xs text-ink-500">بعده تُعاد أرصدة المناديب والصناديق من الحركات الباقية، ويُعاد ترقيم دفتر القيود. حصص المناديب عن استلام شحناته تبقى.</p>
    </section>

    @if ($blockers)
        <section class="card space-y-3 p-5">
            <h2 class="card-title text-bad-700">لا يُحذف وحده</h2>
            <p class="text-sm">ماله مختلطٌ بمال غيره، فحذفه يغيّر كشوفاً أو حسابات حقيقية:</p>
            <ul class="list-disc space-y-1 ps-5 text-sm">
                @foreach ($blockers as $b)<li>{{ $b }}</li>@endforeach
            </ul>
            <p class="text-xs text-ink-500">ألغِ كشوفه أوّلاً، أو احذفه مع «الحسابات والمال» للشركة.</p>
        </section>
    @else
        <form method="POST" action="{{ route('admin.purge.merchant.run') }}" class="card space-y-4 p-5">
            @csrf
            <input type="hidden" name="company" value="{{ $company->id }}">
            <input type="hidden" name="merchant" value="{{ $merchant->code }}">
            <h2 class="card-title text-bad-700">أكّد الحذف</h2>
            <div>
                <label class="field-label" for="confirm_code">اكتب رمز التاجر: <span dir="ltr" class="font-mono">{{ $merchant->code }}</span></label>
                <input id="confirm_code" name="confirm_code" class="field-input" dir="ltr" autocomplete="off" required>
                @error('confirm_code') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="password">كلمة مرورك</label>
                <input id="password" type="password" name="password" class="field-input" autocomplete="current-password" required>
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <label class="flex items-start gap-3 text-sm">
                <input type="checkbox" name="understood" value="1" class="mt-0.5 size-4 shrink-0" required>
                أفهم أنّ هذا الحذف نهائيّ ولا يُسترجع.
            </label>
            @error('understood') <p class="field-error">{{ $message }}</p> @enderror
            @error('merchant') <p class="field-error">{{ $message }}</p> @enderror
            <button type="submit" class="btn-danger w-full justify-center">
                <x-icon name="trash" class="size-5"/> احذف التاجر نهائياً
            </button>
        </form>
    @endif
</div>
@endsection
