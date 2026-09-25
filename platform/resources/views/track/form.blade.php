@extends('layouts.public')
@section('title', 'تتبّع شحنة')

@section('content')
<h1 class="page-title">تتبّع شحنتك</h1>
<p class="page-sub">اكتب رقم الوصل وآخر أربعة أرقام من هاتفك — أو امسح رمز QR على الوصل.</p>

<form method="GET" action="{{ route('track') }}" class="card mt-5 space-y-4 border-t-4 border-t-primary-600 p-5 sm:p-6">
    <div>
        <label class="field-label" for="number">رقم الوصل</label>
        <input id="number" name="number" value="{{ old('number', request('number')) }}" required
               class="field-input text-left" dir="ltr" inputmode="numeric" autocomplete="off" placeholder="000123"
               @error('number') aria-invalid="true" @enderror>
    </div>

    <div>
        <label class="field-label" for="phone">آخر أربعة أرقام من هاتفك</label>
        <input id="phone" name="phone" value="{{ old('phone') }}" required maxlength="4"
               class="field-input text-left" dir="ltr" inputmode="numeric" autocomplete="off" placeholder="4567"
               @error('number') aria-invalid="true" @enderror>
        <p class="field-hint">هاتف المستلم المكتوب على الشحنة — حتى لا يرى شحنتك غيرُك.</p>
    </div>

    @error('number') <p class="field-error" role="alert">{{ $message }}</p> @enderror

    <button type="submit" class="btn-primary w-full py-2.5 text-base">
        <x-icon name="search" class="size-5"/>
        تتبّع
    </button>
</form>
@endsection
