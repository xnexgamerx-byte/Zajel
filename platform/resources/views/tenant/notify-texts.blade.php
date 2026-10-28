@extends('layouts.app')
@section('title', 'نصوص الإشعارات')

@section('content')
<div class="mb-5">
    <h1 class="page-title">نصوص الإشعارات</h1>
    <p class="mt-1 max-w-3xl text-sm text-ink-500">
        ما يصل هواتف التجّار والمناديب والكول سنتر، بكلامكم. الخانات بين القوسين تُملأ وحدها ممّا حدث،
        وما تتركه كما هو يبقى نصّ النظام.
    </p>
</div>

{{-- نصوص الإشعارات (docs/plan/91): لكل إشعارٍ عنوانه ونصّه --}}
<form method="POST" action="{{ route('notify-texts.update') }}" class="space-y-6">
    @csrf
    @method('PUT')

    @foreach (\App\Support\NotifyTexts::AUDIENCES as $audience => $audienceLabel)
        @continue(! $groups->has($audience))
        <section>
            <h2 class="mb-3 text-base font-bold text-ink-900">تصل {{ $audienceLabel }}</h2>
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($groups[$audience] as $key => $text)
                    @php $mine = $own[$key] ?? []; @endphp
                    <div class="card space-y-3 p-4">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-ink-800">حين {{ $text['when'] }}</p>
                            @if ($mine)
                                <span class="chip chip-ok">نصّكم</span>
                            @else
                                <span class="chip chip-mute">نصّ النظام</span>
                            @endif
                        </div>

                        <label class="block">
                            <span class="field-label">العنوان</span>
                            <input type="text" name="texts[{{ $key }}][title]" maxlength="{{ \App\Support\NotifyTexts::MAX_TITLE }}" class="field-input"
                                   value="{{ old("texts.$key.title", $mine['title'] ?? $text['title']) }}">
                        </label>
                        @error("texts.$key.title") <p class="field-error">{{ $message }}</p> @enderror

                        <label class="block">
                            <span class="field-label">النصّ</span>
                            <textarea name="texts[{{ $key }}][body]" rows="2" maxlength="{{ \App\Support\NotifyTexts::MAX_BODY }}" class="field-input">{{ old("texts.$key.body", $mine['body'] ?? $text['body']) }}</textarea>
                        </label>
                        @error("texts.$key.body") <p class="field-error">{{ $message }}</p> @enderror

                        <p class="text-xs text-ink-500">
                            الخانات:
                            @foreach ($text['fields'] as $field => $meaning)
                                <span class="chip chip-mute ms-1" title="{{ $meaning }}">{{ $field }}</span>
                            @endforeach
                        </p>

                        @if ($mine)
                            <label class="flex items-center gap-2 text-xs text-ink-600">
                                <input type="checkbox" name="reset[{{ $key }}]" value="1">
                                أرجعه لنصّ النظام
                            </label>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    <div class="flex gap-2">
        <button type="submit" class="btn-primary">احفظ النصوص</button>
    </div>
</form>
@endsection
