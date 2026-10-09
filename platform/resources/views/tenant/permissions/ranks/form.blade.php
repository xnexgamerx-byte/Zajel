@extends('layouts.app')
@section('title', $rank->exists ? 'مرتبة '.$rank->name : 'مرتبة جديدة')

@section('content')
<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">{{ $rank->exists ? 'مرتبة «'.$rank->name.'»' : 'مرتبة جديدة' }}</h1>
        <p class="mt-1 text-sm text-ink-500">سمِّها، ثم علِّم ما تفتحه من كل قائمة. «الكلّ» يعلّم القائمة كلّها.</p>
    </div>
    <a href="{{ route('permissions.ranks.index') }}" class="btn-ghost">رجوع</a>
</div>

@include('tenant.permissions._tabs')

@unless ($rank->exists)
    {{-- قالبٌ جاهز يملأ الاسم والصلاحيات، ويُعدَّل قبل الحفظ (docs/plan/38) --}}
    <form method="GET" action="{{ route('permissions.ranks.create') }}" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-64 flex-1">
            <label class="field-label" for="template">ابدأ من قالبٍ جاهز (اختياري)</label>
            <select id="template" name="template" class="field-input" data-submit-on-change>
                <option value="">بلا قالب — أختار بنفسي</option>
                @foreach ($templates as $key => $template)
                    <option value="{{ $key }}" @selected($rank->template === $key)>{{ $template['name'] }} — {{ $template['hint'] }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button class="btn-ghost">املأ من القالب</button></noscript>
    </form>
@endunless

<form method="POST" action="{{ $rank->exists ? route('permissions.ranks.update', $rank) : route('permissions.ranks.store') }}">
    @csrf
    @if ($rank->exists) @method('PUT') @endif
    @unless ($rank->exists) <input type="hidden" name="template" value="{{ $rank->template }}"> @endunless

    <section class="card mb-4 grid grid-cols-1 gap-4 p-5 md:grid-cols-2">
        <div>
            <label class="field-label" for="name">اسم المرتبة <span class="text-red-500">*</span></label>
            <input id="name" name="name" class="field-input" required maxlength="80"
                   value="{{ old('name', $rank->name) }}" placeholder="مثل: موظّف رواجع">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="text-sm text-ink-600 md:pt-7">
            @if ($holders->isNotEmpty())
                يحملها: {{ $holders->pluck('name')->implode('، ') }} — والحفظ يسري عليهم فوراً.
            @elseif ($rank->exists)
                لا يحملها أحد بعد.
            @else
                تُعطى للموظّف بعد الحفظ من «الموظّفون» أو من صفحة المستخدم.
            @endif
        </div>
    </section>

    @error('abilities') <div class="card mb-4 border-bad-200 bg-bad-50 p-4 text-sm text-bad-700">{{ $message }}</div> @enderror

    @php $chosen = old('abilities', $rank->abilities ?? []); @endphp

    {{-- الشاشات تحت كل صلاحية مطويّة: تُعرض لمن يريدها، فتبقى الصفحة قصيرة --}}
    <input type="checkbox" id="show-screens" class="peer sr-only">
    <label for="show-screens" class="mb-3 inline-flex cursor-pointer items-center gap-2 text-xs text-[var(--brand)] hover:underline">
        اعرض/أخفِ الشاشات التي تفتحها كل صلاحية
    </label>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3 [&_.ability-screens]:hidden peer-checked:[&_.ability-screens]:block">
        @foreach ($groups as $key => $group)
            <fieldset class="card p-5" data-check-scope aria-labelledby="menu-{{ $key }}">
                <label class="mb-3 flex items-center justify-between gap-3 border-b border-ink-100 pb-3">
                    <span id="menu-{{ $key }}" class="font-heading text-base font-medium text-aeblack-950">{{ $group['label'] }}</span>
                    <span class="flex items-center gap-2 text-xs text-ink-500">
                        الكلّ
                        <input type="checkbox" data-check-all class="size-4 accent-[var(--brand)]">
                    </span>
                </label>
                <div class="space-y-3">
                    @foreach ($group['abilities'] as $ability => $label)
                        <label class="flex items-start gap-2.5 text-sm" title="{{ implode(' · ', $screens[$ability] ?? []) }}">
                            <input type="checkbox" name="abilities[]" value="{{ $ability }}"
                                   class="mt-1 size-4 shrink-0 accent-[var(--brand)]"
                                   @checked(in_array($ability, $chosen, true))>
                            <span>
                                <span class="text-ink-900">{{ $label }}</span>
                                @if (! empty($screens[$ability]))
                                    <span class="ability-screens text-xs leading-5 text-ink-500">{{ implode(' · ', $screens[$ability]) }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <button type="submit" class="btn-primary">{{ $rank->exists ? 'احفظ المرتبة' : 'أنشئ المرتبة' }}</button>
        <p class="text-xs text-ink-500">ما لا تفتحه المرتبة يُرفض ولو طُلب مباشرة، لا يُخفى زرّه فقط.</p>
    </div>
</form>
@endsection
