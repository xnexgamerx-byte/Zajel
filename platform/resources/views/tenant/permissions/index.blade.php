@extends('layouts.app')
@section('title', 'الصلاحيات')

@section('content')
<div class="mb-5">
    <h1 class="text-xl font-bold">الصلاحيات</h1>
    <p class="mt-1 text-sm text-ink-500">
        ما يستطيعه كل مستخدم فعلاً — لا ما يُفترض بدوره.
    </p>
</div>

<div class="space-y-4">
    @foreach ($users as $user)
        @php $abilities = $user->abilities(); @endphp
        <section class="card p-5">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="font-bold">{{ $user->name }}</div>
                    <div class="num text-sm text-ink-500">
                        {{ $user->phone }} · {{ $user->role->label() }}
                        @unless ($user->is_active) · <span class="text-bad-700">موقوف</span> @endunless
                    </div>
                </div>
                <span class="chip {{ $user->hasCustomPermissions() ? 'chip-info' : 'chip-mute' }}">
                    {{ $user->hasCustomPermissions() ? 'صلاحيات مخصَّصة' : 'افتراضي الدور' }}
                    ({{ count($abilities) }}/{{ count(\App\Support\Permissions\Ability::all()) }})
                </span>
            </div>

            <form method="POST" action="{{ route('permissions.update', $user) }}">
                @csrf
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                    @foreach ($groups as $group)
                        <div class="rounded-lg border border-ink-200 p-3">
                            <div class="mb-2 text-xs font-bold text-ink-500">{{ $group['label'] }}</div>
                            <div class="space-y-1.5">
                                @foreach ($group['abilities'] as $ability => $label)
                                    <label class="flex items-start gap-2 text-sm">
                                        <input type="checkbox" name="abilities[]" value="{{ $ability }}"
                                               class="mt-0.5 size-4 shrink-0 accent-[var(--brand)]"
                                               @checked(in_array($ability, $abilities, true))>
                                        <span class="{{ in_array($ability, $abilities, true) ? 'text-ink-800' : 'text-ink-500' }}">
                                            {{ $label }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                @error('abilities') <p class="field-error mt-3">{{ $message }}</p> @enderror

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button type="submit" name="mode" value="custom" class="btn-primary">احفظ هذا التخصيص</button>
                    @if ($user->hasCustomPermissions())
                        <button type="submit" name="mode" value="role" class="btn-ghost">
                            أعِده إلى افتراضي «{{ $user->role->label() }}»
                        </button>
                    @endif
                    <p class="ms-auto text-xs text-ink-500">
                        المنع في المسار لا في الشاشة: إخفاء الزرّ ليس منعاً.
                    </p>
                </div>
            </form>
        </section>
    @endforeach
</div>

<section class="card mt-5 p-5">
    <h2 class="card-title">افتراضيّات الأدوار</h2>
    <p class="card-hint mb-4">ما يناله المستخدم إن لم يُخصَّص له شيء. تغييرها يسري على كل من لم يُخصَّص.</p>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>الدور</th>
                    @foreach ($groups as $group)<th>{{ $group['label'] }}</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($roles as $role)
                    @php $defaults = \App\Support\Permissions\Ability::defaultsFor($role); @endphp
                    <tr>
                        <td class="font-medium">{{ $role->label() }}</td>
                        @foreach ($groups as $group)
                            @php
                                $have = count(array_intersect(array_keys($group['abilities']), $defaults));
                                $total = count($group['abilities']);
                            @endphp
                            <td class="num {{ $have === 0 ? 'text-ink-400' : ($have === $total ? 'text-ok-700 font-semibold' : 'text-ink-700') }}">
                                {{ $have }}/{{ $total }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection
