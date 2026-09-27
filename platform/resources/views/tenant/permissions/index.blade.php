@extends('layouts.app')
@section('title', 'الصلاحيات والمراتب')

@section('content')
<div class="mb-4">
    <h1 class="page-title">الصلاحيات والمراتب</h1>
    <p class="mt-1 text-sm text-ink-500">
        ما يستطيعه كل موظّف فعلاً — لا ما يُفترض بدوره. المرتبة تحدّد شاشاته، والاستثنائية تزيده صلاحيةً بعينها.
    </p>
</div>

@include('tenant.permissions._tabs')

@php $legacy = $users->filter->hasCustomPermissions(); @endphp

@if ($legacy->isNotEmpty())
    <div class="card mb-4 border-warn-200 bg-warn-50 p-4 text-sm text-warn-700">
        <span class="font-semibold">{{ $legacy->count() === 1 ? 'موظّفٌ واحد صلاحياته' : $legacy->count().' موظّفين صلاحياتهم' }} مخصّصةٌ بالطريقة السابقة للمراتب.</span>
        تعمل كما هي. احفظها مرتبةً باسم فتُسند لغيره وتُعدَّل في مكانٍ واحد، أو أزلها فيعود إلى مرتبته.
    </div>
@endif

@error('rank_id') <div class="card mb-4 border-bad-200 bg-bad-50 p-4 text-sm text-bad-700">{{ $message }}</div> @enderror
@error('abilities') <div class="card mb-4 border-bad-200 bg-bad-50 p-4 text-sm text-bad-700">{{ $message }}</div> @enderror
@error('name') <div class="card mb-4 border-bad-200 bg-bad-50 p-4 text-sm text-bad-700">{{ $message }}</div> @enderror

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>الموظّف</th>
                    <th>الدور</th>
                    <th>المرتبة</th>
                    <th>استثنائية</th>
                    <th>ما يملكه</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    @php $abilities = $user->abilities(); @endphp
                    <tr class="align-top {{ $user->is_active ? '' : 'opacity-60' }}">
                        <td class="min-w-44">
                            <div class="font-semibold">{{ $user->name }}</div>
                            <div class="num text-xs text-ink-500" dir="ltr">{{ $user->username }}</div>
                            @unless ($user->is_active) <div class="text-xs text-bad-700">موقوف</div> @endunless
                        </td>
                        <td class="whitespace-nowrap">
                            {{ $user->role->label() }}
                            @if ($user->branch) <div class="text-xs text-ink-500">{{ $user->branch->name }}</div> @endif
                        </td>
                        <td class="min-w-60">
                            @if ($user->hasCustomPermissions())
                                <span class="chip chip-warn">تخصيصٌ قديم</span>
                                <form method="POST" action="{{ route('permissions.update', $user) }}" class="mt-2 flex flex-wrap items-center gap-2">
                                    @csrf
                                    <input type="hidden" name="mode" value="save_as_rank">
                                    <input name="name" class="field-input w-40 py-1.5" placeholder="اسم المرتبة" required maxlength="80"
                                           aria-label="احفظ صلاحيات {{ $user->name }} مرتبةً باسم">
                                    <button class="btn-ghost py-1">احفظها مرتبة</button>
                                </form>
                                <form method="POST" action="{{ route('permissions.update', $user) }}" class="mt-1.5">
                                    @csrf
                                    <input type="hidden" name="mode" value="reset">
                                    <button class="text-xs font-semibold text-bad-700 hover:underline">أزل التخصيص</button>
                                </form>
                            @elseif ($user->role === \App\Enums\UserRole::CompanyOwner)
                                <span class="text-ink-600">كل شيء — صاحب الشركة</span>
                            @else
                                <form method="POST" action="{{ route('permissions.update', $user) }}" class="flex items-center gap-2">
                                    @csrf
                                    <input type="hidden" name="mode" value="rank">
                                    <select name="rank_id" class="field-input w-48 py-1.5" aria-label="مرتبة {{ $user->name }}">
                                        <option value="">افتراضي «{{ $user->role->label() }}»</option>
                                        @foreach ($ranks as $rank)
                                            <option value="{{ $rank->id }}" @selected($user->rank_id === $rank->id)>{{ $rank->name }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn-ghost py-1">حفظ</button>
                                </form>
                            @endif
                        </td>
                        <td class="whitespace-nowrap">
                            @if ($user->grants->isNotEmpty())
                                <a href="{{ route('permissions.grants.index', ['user_id' => $user->id]) }}"
                                   class="font-semibold text-[var(--brand)] hover:underline">
                                    {{ $user->grants->count() === 1 ? 'واحدة' : $user->grants->count() }}
                                </a>
                            @else
                                <span class="text-ink-400">—</span>
                            @endif
                        </td>
                        <td class="min-w-56">
                            <details>
                                <summary class="cursor-pointer text-sm">
                                    <span class="num font-semibold">{{ count($abilities) }}</span><span class="text-ink-500">/{{ $total }}</span>
                                    <span class="text-xs text-ink-500">— {{ $user->abilitySource() }}</span>
                                </summary>
                                <div class="mt-2 space-y-1.5 text-xs">
                                    @foreach ($groups as $group)
                                        @php $have = array_intersect(array_keys($group['abilities']), $abilities); @endphp
                                        @continue($have === [])
                                        <div>
                                            <span class="font-semibold text-ink-700">{{ $group['label'] }}:</span>
                                            <span class="text-ink-600">{{ collect($have)->map(fn ($a) => $group['abilities'][$a])->implode('، ') }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<p class="mt-3 text-xs text-ink-500">
    المنع في المسار لا في الشاشة: إخفاء الزرّ ليس منعاً. والمرتبة تُسند أيضاً من صفحة المستخدم.
</p>
@endsection
