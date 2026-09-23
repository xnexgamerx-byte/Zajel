@extends('layouts.app')
@section('title', 'الأكياس')

@section('content')
<div class="mb-5">
    <h1 class="page-title">الأكياس</h1>
    <p class="mt-1 text-sm text-ink-500">
        نقل مئة طرد بين مركزين يعني مسح كيس واحد لا مئة باركود.
    </p>
</div>

<div class="mb-5 flex flex-wrap gap-2">
    @foreach (['' => 'المتداولة', 'open' => 'مفتوح', 'sealed' => 'مختوم',
               'in_transit' => 'في الطريق', 'received' => 'وصل', 'opened' => 'فُتح'] as $value => $label)
        <a href="{{ route('bags.index', array_filter(['status' => $value])) }}"
           class="chip {{ request('status', '') === $value ? 'chip-info' : 'chip-mute' }}">
            {{ $label }}{{ $value && isset($counts[$value]) ? ' ('.number_format($counts[$value]).')' : '' }}
        </a>
    @endforeach
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <section class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>الكيس</th>
                            <th>المسار</th>
                            <th>الشحنات</th>
                            <th>الحالة</th>
                            <th>آخر تغيير</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($bags as $bag)
                            <tr>
                                <td>
                                    <a href="{{ route('bags.show', $bag) }}"
                                       class="num font-semibold text-ink-900 hover:underline">{{ $bag->code }}</a>
                                </td>
                                <td class="text-ink-600">
                                    {{ $bag->fromHub?->name }} ← {{ $bag->toHub?->name }}
                                </td>
                                <td class="num">{{ number_format($bag->shipments_count) }}</td>
                                <td><span class="chip {{ $bag->statusTone() }}">{{ $bag->statusLabel() }}</span></td>
                                <td class="text-sm text-ink-500">{{ $bag->updated_at->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-10 text-center text-ink-500">لا أكياس في هذه الحالة.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($bags->hasPages())
                <div class="border-t border-ink-100 px-5 py-4">{{ $bags->links() }}</div>
            @endif
        </section>
    </div>

    <section class="card h-fit p-5">
        <h2 class="card-title">كيس جديد</h2>
        <form method="POST" action="{{ route('bags.store') }}" class="mt-3 space-y-3">
            @csrf
            <div>
                <label class="field-label" for="from_hub_id">من مركز</label>
                <select id="from_hub_id" name="from_hub_id" class="field-input" required>
                    @foreach ($hubs as $hub)
                        <option value="{{ $hub->id }}" @selected($home?->id === $hub->id)>{{ $hub->name }}</option>
                    @endforeach
                </select>
                @error('from_hub_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="to_hub_id">إلى مركز</label>
                <select id="to_hub_id" name="to_hub_id" class="field-input" required>
                    @foreach ($hubs as $hub)
                        <option value="{{ $hub->id }}"
                                @selected($away?->id === $hub->id)>
                            {{ $hub->name }}
                        </option>
                    @endforeach
                </select>
                @error('to_hub_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="notes">ملاحظة</label>
                <input id="notes" name="notes" type="text" maxlength="500" class="field-input">
            </div>
            <button type="submit" class="btn-primary w-full" @disabled($hubs->count() < 2)>أنشئ الكيس</button>
            @if ($hubs->count() < 2)
                <p class="field-hint">تحتاج مركزين على الأقل. أضف مركزاً من إعدادات الفروع.</p>
            @endif
        </form>
    </section>
</div>
@endsection
