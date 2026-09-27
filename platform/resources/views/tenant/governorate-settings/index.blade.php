@extends('layouts.app')
@section('title', 'إعدادات المحافظات')

@section('content')
<div class="mb-5">
    <h1 class="page-title">إعدادات المحافظات</h1>
    <p class="mt-1 text-sm text-ink-500">
        ما تشحن إليه شركتك وبأيّ ترتيبٍ يظهر في القوائم، وأجرة المندوب إلى مركز كل محافظة وإلى أقضيتها وأطرافها.
        مبلغا الشحن من
        @if ($default)
            <a href="{{ route('pricing.edit', $default) }}" class="text-[var(--brand)] hover:underline">{{ $default->name }}</a>
        @else
            التسعيرة الافتراضية
        @endif
        ويُحرَّران فيها.
    </p>
</div>

<form method="POST" action="{{ route('governorate-settings.update') }}" class="card overflow-hidden">
    @csrf
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>المحافظة</th>
                    <th>الكود</th>
                    <th>نشطة</th>
                    <th>الترتيب</th>
                    <th>مبلغ الشحن</th>
                    <th>للأقضية</th>
                    <th title="لمن لا أجرة توصيلٍ في بطاقته">أجرة المندوب</th>
                    <th>أجرة المندوب للأقضية</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($governorates as $gov)
                    @php $setting = $settings[$gov->id] ?? null; $rule = $rules[$gov->id] ?? $rules[0] ?? null; @endphp
                    <tr @class(['opacity-60' => $setting && ! $setting->is_active])>
                        <td class="font-medium">{{ $gov->name_ar }} <span class="text-xs text-ink-500" dir="ltr">{{ $gov->name_en }}</span></td>
                        <td class="num text-xs">{{ $gov->code }}</td>
                        <td>
                            <input type="hidden" name="rows[{{ $gov->id }}][is_active]" value="0">
                            <input type="checkbox" name="rows[{{ $gov->id }}][is_active]" value="1" class="size-4 accent-[var(--brand)]"
                                   @checked(old("rows.{$gov->id}.is_active", $setting?->is_active ?? true)) aria-label="{{ $gov->name_ar }} نشطة">
                        </td>
                        <td>
                            <input name="rows[{{ $gov->id }}][sort_order]" type="number" min="0" max="999" dir="ltr"
                                   class="field-input w-20 text-left" placeholder="{{ $gov->sort_order }}"
                                   value="{{ old("rows.{$gov->id}.sort_order", $setting?->sort_order) }}" aria-label="ترتيب {{ $gov->name_ar }}">
                        </td>
                        <td class="num">{{ $rule ? number_format($rule->delivery_fee) : '—' }}</td>
                        <td class="num">{{ $rule?->peripheral_fee !== null ? number_format($rule->peripheral_fee) : ($rule ? 'كالمركز' : '—') }}</td>
                        @foreach (['courier_fee', 'courier_fee_peripheral'] as $field)
                            <td>
                                <input name="rows[{{ $gov->id }}][{{ $field }}]" type="number" min="0" step="250" dir="ltr"
                                       class="field-input w-28 text-left" placeholder="—"
                                       value="{{ old("rows.{$gov->id}.{$field}", $setting?->{$field}) }}"
                                       aria-label="{{ $field === 'courier_fee' ? 'أجرة المندوب' : 'أجرة المندوب للأقضية' }} — {{ $gov->name_ar }}">
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-ink-100 px-5 py-3">
        <p class="text-xs text-ink-500">
            المحافظة غير النشطة لا تظهر عند إنشاء الشحنات ولا يُشحَن إليها. وأجرة المندوب هنا لمن تُرك «عمولة التوصيل» في بطاقته فارغاً.
        </p>
        <button type="submit" class="btn-primary">احفظ</button>
    </div>
</form>
@endsection
