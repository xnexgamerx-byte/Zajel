@extends('layouts.app')
@section('title', $rank->exists ? 'مرتبة '.$rank->name : 'مرتبة جديدة')

@section('content')
<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">{{ $rank->exists ? 'مرتبة «'.$rank->name.'»' : 'مرتبة جديدة' }}</h1>
        <p class="mt-1 text-sm text-ink-500">
            اختر من كل قائمة ما تفتحه المرتبة. تحت كل صلاحية الشاشات التي تفتحها في الشريط.
        </p>
    </div>
    <a href="{{ route('permissions.ranks.index') }}" class="btn-ghost">رجوع</a>
</div>

@include('tenant.permissions._tabs')

@unless ($rank->exists)
    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        <span class="text-ink-500">ابدأ من قالب:</span>
        @foreach ($templates as $key => $template)
            <a href="{{ route('permissions.ranks.create', ['template' => $key]) }}" title="{{ $template['hint'] }}"
               @class(['chip', 'chip-info' => $rank->template === $key, 'chip-mute hover:bg-primary-50 hover:text-primary-700' => $rank->template !== $key])>
                {{ $template['name'] }}
            </a>
        @endforeach
    </div>
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
                تُسند بعد الحفظ من «الموظّفون ومراتبهم» أو من صفحة المستخدم.
            @endif
        </div>
    </section>

    @error('abilities') <div class="card mb-4 border-bad-200 bg-bad-50 p-4 text-sm text-bad-700">{{ $message }}</div> @enderror

    @php $chosen = old('abilities', $rank->abilities ?? []); @endphp

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
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
                        <label class="flex items-start gap-2.5 text-sm">
                            <input type="checkbox" name="abilities[]" value="{{ $ability }}"
                                   class="mt-1 size-4 shrink-0 accent-[var(--brand)]"
                                   @checked(in_array($ability, $chosen, true))>
                            <span>
                                <span class="text-ink-900">{{ $label }}</span>
                                @if (! empty($screens[$ability]))
                                    <span class="block text-xs leading-5 text-ink-500">{{ implode(' · ', $screens[$ability]) }}</span>
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
        <p class="text-xs text-ink-500">المنع في المسار لا في الشاشة: ما لا تفتحه المرتبة يُرفض ولو طُلب مباشرة.</p>
    </div>
</form>
@endsection
