@extends('layouts.app')
@section('title', 'صلاحيات استثنائية')

@section('content')
<div class="mb-4">
    <h1 class="page-title">صلاحيات استثنائية</h1>
    <p class="mt-1 text-sm text-ink-500">
        صلاحيةٌ لموظّفٍ بعينه فوق مرتبته — كتعديل الشحنات لموظّف إدخالٍ واحد لا لمرتبته كلّها. تُرى بمن منحها ومتى، وتُسحب بزرّ.
    </p>
</div>

@include('tenant.permissions._tabs')

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <form method="GET" class="card flex flex-wrap items-end gap-3 p-4">
            <div class="min-w-48 flex-1">
                <label class="field-label" for="filter_user">الموظّف</label>
                <select id="filter_user" name="user_id" class="field-input">
                    <option value="">الكلّ</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected((string) request('user_id') === (string) $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-48 flex-1">
                <label class="field-label" for="filter_ability">الصلاحية</label>
                <select id="filter_ability" name="ability" class="field-input">
                    <option value="">الكلّ</option>
                    @foreach ($groups as $group)
                        <optgroup label="{{ $group['label'] }}">
                            @foreach ($group['abilities'] as $ability => $label)
                                <option value="{{ $ability }}" @selected(request('ability') === $ability)>{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-primary">عرض</button>
            @if (request('user_id') || request('ability'))
                <a href="{{ route('permissions.grants.index') }}" class="btn-ghost">مسح</a>
            @endif
        </form>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>الموظّف</th>
                            <th>مرتبته</th>
                            <th>الصلاحية</th>
                            <th>منحها</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($grants as $grant)
                            <tr class="align-top">
                                <td class="font-semibold whitespace-nowrap">{{ $grant->user?->name }}</td>
                                <td class="text-ink-600 whitespace-nowrap">{{ $grant->user?->abilitySource() }}</td>
                                <td class="min-w-48">
                                    <span class="text-xs text-ink-500">{{ \App\Support\Permissions\Ability::menuOf($grant->ability) }} ›</span>
                                    {{ \App\Support\Permissions\Ability::label($grant->ability) }}
                                    @if ($grant->note) <div class="text-xs text-ink-500">{{ $grant->note }}</div> @endif
                                </td>
                                <td class="whitespace-nowrap text-sm">
                                    {{ $grant->granted_by_name ?? '—' }}
                                    <div class="num text-xs text-ink-500">{{ $grant->created_at->format('Y-m-d H:i') }}</div>
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('permissions.grants.destroy', $grant) }}">
                                        @csrf @method('DELETE')
                                        <button class="text-sm font-semibold text-bad-700 hover:underline">اسحبها</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-12 text-center text-ink-500">
                                {{ request('user_id') || request('ability') ? 'لا استثناء بهذا البحث.' : 'لا صلاحيات استثنائية: كلٌّ على مرتبته.' }}
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($grants->hasPages())
                <div class="border-t border-ink-100 px-4 py-3">{{ $grants->links() }}</div>
            @endif
        </div>
    </div>

    <section class="card h-fit p-5">
        <h2 class="card-title">امنح صلاحية</h2>
        <form method="POST" action="{{ route('permissions.grants.store') }}" class="mt-3 space-y-3">
            @csrf
            <div>
                <label class="field-label" for="user_id">الموظّف <span class="text-red-500">*</span></label>
                <select id="user_id" name="user_id" class="field-input" data-searchable required>
                    <option value="">اختر الموظّف</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected((string) old('user_id', request('user_id')) === (string) $user->id)>
                            {{ $user->name }} — {{ $user->rank?->name ?? $user->role->label() }}{{ $user->is_active ? '' : ' (موقوف)' }}
                        </option>
                    @endforeach
                </select>
                @error('user_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="ability">الصلاحية <span class="text-red-500">*</span></label>
                <select id="ability" name="ability" class="field-input" required>
                    <option value="">اختر الصلاحية</option>
                    @foreach ($groups as $group)
                        <optgroup label="{{ $group['label'] }}">
                            @foreach ($group['abilities'] as $ability => $label)
                                <option value="{{ $ability }}" @selected(old('ability') === $ability)>{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @error('ability') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="note">السبب</label>
                <input id="note" name="note" class="field-input" maxlength="255" value="{{ old('note') }}"
                       placeholder="مثل: يصحّح أخطاء الإدخال في الفرع">
            </div>
            <button type="submit" class="btn-primary w-full" @disabled($users->isEmpty())>امنحها</button>
            <p class="field-hint">صاحب الشركة لا يظهر هنا: يملك كل شيء أصلاً.</p>
        </form>
    </section>
</div>
@endsection
