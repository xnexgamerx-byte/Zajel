@extends('layouts.app')
@section('title', 'تسعيرة ' . $list->name)

@section('content')
<form method="POST" action="{{ route('pricing.update', $list) }}">
    @csrf
    @method('PUT')

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="page-title">{{ $list->name }}</h1>
            <p class="mt-1 text-sm text-ink-500">
                اترك أجرة التوصيل فارغة لتُحذف قاعدة تلك المحافظة وتُستعمل القاعدة العامة.
            </p>
        </div>
        <a href="{{ route('pricing.index') }}" class="btn-ghost">رجوع</a>
    </div>

    <section class="card mb-4 p-5">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <label class="field-label" for="name">اسم التسعيرة</label>
                <input id="name" name="name" class="field-input" required value="{{ old('name', $list->name) }}">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="weight_to_grams">الوزن المشمول بالأجرة (غرام)</label>
                <input id="weight_to_grams" name="weight_to_grams" type="number" min="1" step="1"
                       class="field-input text-left" dir="ltr" required
                       value="{{ old('weight_to_grams', $rules->first()?->weight_to_grams ?? 5000) }}">
                <p class="mt-1 text-xs text-ink-500">ما زاد يُحتسب بأجرة الكيلو الزائد.</p>
                @error('weight_to_grams') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end gap-5 pb-2">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $list->is_default))
                           class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                    الافتراضية
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $list->is_active))
                           class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                    مفعّلة
                </label>
            </div>
        </div>
    </section>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="px-3 py-3 text-start font-semibold">الوجهة</th>
                        <th class="px-3 py-3 text-start font-semibold">التوصيل</th>
                        <th class="px-3 py-3 text-start font-semibold">الراجع</th>
                        <th class="px-3 py-3 text-start font-semibold">الاستبدال</th>
                        <th class="px-3 py-3 text-start font-semibold">كغم زائد</th>
                        <th class="px-3 py-3 text-start font-semibold">عمولة تحصيل</th>
                        <th class="px-3 py-3 text-start font-semibold">نسبة %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @php
                        $rows = collect([['key' => 0, 'label' => 'كل العراق (قاعدة عامة)', 'general' => true]])
                            ->concat($governorates->map(fn ($g) => [
                                'key' => $g->id, 'label' => $g->name_ar, 'general' => false,
                            ]));
                    @endphp

                    @foreach ($rows as $row)
                        @php $rule = $rules[$row['key']] ?? null; @endphp
                        <tr class="{{ $row['general'] ? 'bg-ink-50/60' : 'hover:bg-ink-50' }}">
                            <td class="px-3 py-2 font-medium {{ $row['general'] ? 'font-bold' : '' }}">
                                {{ $row['label'] }}
                            </td>
                            @foreach ([
                                ['delivery_fee', $rule?->delivery_fee],
                                ['return_fee', $rule?->return_fee],
                                ['replacement_fee', $rule?->replacement_fee],
                                ['extra_kg_fee', $rule?->extra_kg_fee],
                                ['cod_fee_flat', $rule?->cod_fee_flat],
                            ] as [$field, $value])
                                <td class="px-3 py-2">
                                    <input name="rows[{{ $row['key'] }}][{{ $field }}]" type="number" min="0"
                                           step="1" dir="ltr"
                                           class="field-input w-28 text-left"
                                           value="{{ old("rows.{$row['key']}.{$field}", $rule ? $value : null) }}"
                                           placeholder="{{ $field === 'delivery_fee' ? '—' : '0' }}">
                                </td>
                            @endforeach
                            <td class="px-3 py-2">
                                <input name="rows[{{ $row['key'] }}][cod_fee_percent]" type="number" min="0" max="100"
                                       step="0.1" dir="ltr" class="field-input w-20 text-left"
                                       value="{{ old("rows.{$row['key']}.cod_fee_percent", $rule?->cod_fee_percent) }}"
                                       placeholder="0">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-ink-100 px-5 py-4">
            <p class="text-xs text-ink-500">
                المبالغ بالدينار العراقي. قاعدة المحافظة تغلب القاعدة العامة عند تطابقهما.
            </p>
            <button type="submit" class="btn-primary">احفظ التسعيرة</button>
        </div>
    </div>
</form>
@endsection
