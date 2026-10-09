@extends('layouts.app')
@section('title', 'الصلاحيات والمراتب')

@section('content')
<div class="mb-4">
    <h1 class="page-title">الصلاحيات والمراتب</h1>
    <p class="mt-1 text-sm text-ink-500">لكل موظّفٍ مرتبته، وتحتها القوائم التي يراها في الشريط.</p>
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
                    <th>مرتبته</th>
                    <th>يرى في الشريط</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    @php $abilities = $user->abilities(); @endphp
                    <tr class="align-top {{ $user->is_active ? '' : 'opacity-60' }}">
                        <td class="min-w-44">
                            <div class="font-semibold">{{ $user->name }}</div>
                            <div class="text-xs text-ink-500">
                                {{ $user->role->label() }}@if ($user->branch) · {{ $user->branch->name }}@endif
                            </div>
                            @unless ($user->is_active) <div class="text-xs text-bad-700">موقوف</div> @endunless
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
                                <span class="text-ink-600">صاحب الشركة: كل شيء</span>
                            @else
                                {{-- المرتبة تُحفظ باختيارها: لا زرّ «حفظ» في كل صفّ --}}
                                <form method="POST" action="{{ route('permissions.update', $user) }}">
                                    @csrf
                                    <input type="hidden" name="mode" value="rank">
                                    <select name="rank_id" class="field-input w-60 py-1.5" aria-label="مرتبة {{ $user->name }}" data-submit-on-change>
                                        <option value="">حسب دوره: {{ $user->role->label() }}</option>
                                        @foreach ($ranks as $rank)
                                            <option value="{{ $rank->id }}" @selected($user->rank_id === $rank->id)>{{ $rank->name }}</option>
                                        @endforeach
                                    </select>
                                    <noscript><button class="btn-ghost mt-1 py-1">حفظ</button></noscript>
                                </form>
                            @endif
                        </td>
                        <td class="min-w-64">
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($groups as $group)
                                    @php $have = array_intersect(array_keys($group['abilities']), $abilities); @endphp
                                    @continue($have === [])
                                    {{-- القائمة باسمها؛ وتفاصيلها تظهر بالمرور عليها --}}
                                    <span class="chip chip-mute" title="{{ collect($have)->map(fn ($a) => $group['abilities'][$a])->implode('، ') }}">
                                        {{ $group['label'] }}
                                    </span>
                                @endforeach
                                @if ($abilities === [])
                                    <span class="text-xs text-ink-400">لا شيء بعد</span>
                                @endif
                                @if ($user->grants->isNotEmpty())
                                    <a href="{{ route('permissions.grants.index', ['user_id' => $user->id]) }}" class="chip chip-info hover:underline">
                                        {{ $user->grants->count() === 1 ? '+ صلاحية إضافية واحدة' : '+ '.$user->grants->count().' صلاحيات إضافية' }}
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<p class="mt-3 text-xs text-ink-500">
    ما لا تفتحه المرتبة يُرفض ولو طُلب مباشرة، لا يُخفى زرّه فقط. والمرتبة تُعطى أيضاً من صفحة المستخدم.
</p>
@endsection
