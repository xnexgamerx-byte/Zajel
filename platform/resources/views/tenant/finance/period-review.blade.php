@extends('layouts.app')
@section('title', 'مراجعة ما قبل الإقفال')

@section('content')
@include('tenant.finance._tabs', ['title' => 'مراجعة ما قبل الإقفال', 'lead' => 'أرقام الفترة كما ستُحفظ، وفحوصها. ما لم ينجح يحتاج سبباً مكتوباً يُحفظ مع الإقفال، أو يُصلَح أولاً.'])

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div><label class="field-label" for="from">من</label><input id="from" type="date" name="from" value="{{ $from->toDateString() }}" class="field-input"></div>
    <div><label class="field-label" for="to">إلى</label><input id="to" type="date" name="to" value="{{ $to->toDateString() }}" class="field-input"></div>
    <button type="submit" class="btn-ghost">تحديث</button>
</form>

@error('period') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror
@error('exceptions') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror

<section class="card mb-5 p-5">
    <h2 class="card-title mb-3">أرقام الفترة <span class="num text-sm font-normal text-ink-500">{{ $from->toDateString() }} — {{ $to->toDateString() }}</span></h2>
    @include('tenant.finance._period-figures', ['figures' => $figures])
</section>

<section class="card mb-5 overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">الفحوص</h2>
    @include('tenant.finance._period-checks', ['checks' => $checks, 'exceptions' => [], 'exceptionInputs' => auth()->user()->can('periods.prepare')])
</section>

@can('periods.prepare')
    <form id="prepare-form" method="POST" action="{{ route('finance.periods.store') }}" class="card flex flex-wrap items-center gap-3 p-4"
          data-confirm="إعداد إقفال الفترة {{ $from->toDateString() }} — {{ $to->toDateString() }}؟ يعتمده المخوَّل بعدها.">
        @csrf
        <input type="hidden" name="from" value="{{ $from->toDateString() }}">
        <input type="hidden" name="to" value="{{ $to->toDateString() }}">
        <button class="btn-primary">إعداد الإقفال</button>
        <span class="text-xs text-ink-500">الإعداد لا يُقفل الفترة: يحفظ أرقامها وفحوصها حتى يعتمدها المخوَّل.</span>
    </form>
@endcan
@endsection
