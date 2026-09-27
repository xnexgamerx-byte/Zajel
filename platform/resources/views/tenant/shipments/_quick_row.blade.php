@php
    /** @var int|string $i  رقم الصفّ، أو __I__ في القالب */
    $old = fn (string $field, $default = null) => is_int($i) ? old("rows.{$i}.{$field}", $default) : $default;
    $err = fn (string $field) => is_int($i) ? $errors->first("rows.{$i}.{$field}") : null;
    $cell = 'field-input px-3 py-1.5';
    $govId = $mode === 'merchant' ? (int) $old('governorate_id', $baghdad) : null;
@endphp
<tr data-quick-row class="align-top">
    <td class="num px-2 py-2 text-ink-400" data-quick-index>{{ is_int($i) ? $i + 1 : '' }}</td>

    @if ($mode === 'governorate')
        <td class="min-w-48 px-1 py-2">
            <select name="rows[{{ $i }}][merchant_id]" class="{{ $cell }}" data-searchable aria-label="التاجر"
                    @if ($err('merchant_id')) aria-invalid="true" @endif>
                <option value="">التاجر</option>
                @foreach ($merchants as $merchant)
                    <option value="{{ $merchant->id }}" @selected((int) $old('merchant_id') === $merchant->id)>{{ $merchant->business_name }}</option>
                @endforeach
            </select>
            @if ($err('merchant_id')) <p class="field-error text-xs">{{ $err('merchant_id') }}</p> @endif
        </td>
    @endif

    <td class="w-28 px-1 py-2">
        <input name="rows[{{ $i }}][amount]" value="{{ $old('amount') }}" class="{{ $cell }} text-left" dir="ltr"
               inputmode="decimal" placeholder="بالألف" aria-label="المبلغ بالألف" data-quick-amount
               @if ($err('amount')) aria-invalid="true" title="{{ $err('amount') }}" @endif>
        <p class="num mt-0.5 min-h-4 text-[11px] text-ink-500" data-quick-preview></p>
    </td>
    <td class="w-36 px-1 py-2">
        <input name="rows[{{ $i }}][recipient_phone]" value="{{ $old('recipient_phone') }}" class="{{ $cell }} text-left" dir="ltr"
               inputmode="tel" placeholder="07xxxxxxxxx" aria-label="هاتف المستلم"
               @if ($err('recipient_phone')) aria-invalid="true" @endif>
        @if ($err('recipient_phone')) <p class="field-error text-xs">{{ $err('recipient_phone') }}</p> @endif
    </td>
    <td class="min-w-32 px-1 py-2">
        <input name="rows[{{ $i }}][recipient_name]" value="{{ $old('recipient_name') }}" class="{{ $cell }}"
               placeholder="اختياري" aria-label="اسم المستلم">
    </td>

    @if ($mode === 'merchant')
        <td class="w-36 px-1 py-2">
            <select name="rows[{{ $i }}][governorate_id]" class="{{ $cell }}" aria-label="المحافظة" data-quick-governorate
                    @if ($err('governorate_id')) aria-invalid="true" @endif>
                @foreach ($governorates as $gov)
                    <option value="{{ $gov->id }}" @selected($govId === $gov->id)>{{ $gov->name_ar }}</option>
                @endforeach
            </select>
        </td>
    @endif

    <td class="min-w-44 px-1 py-2">
        <select name="rows[{{ $i }}][city_id]" class="{{ $cell }}" data-searchable data-quick-city
                data-old="{{ $old('city_id') }}" data-empty-label="المنطقة" aria-label="المنطقة"
                @if ($err('city_id')) aria-invalid="true" @endif>
            <option value="">المنطقة</option>
        </select>
        @if ($err('city_id')) <p class="field-error text-xs">{{ $err('city_id') }}</p> @endif
    </td>
    <td class="min-w-56 px-1 py-2">
        <input name="rows[{{ $i }}][address]" value="{{ $old('address') }}" class="{{ $cell }}"
               placeholder="الشارع وأقرب نقطة دالّة" aria-label="العنوان"
               @if ($err('address')) aria-invalid="true" @endif>
        @if ($err('address')) <p class="field-error text-xs">{{ $err('address') }}</p> @endif
    </td>
    <td class="w-28 px-1 py-2">
        <input name="rows[{{ $i }}][merchant_reference]" value="{{ $old('merchant_reference') }}" class="{{ $cell }} text-left" dir="ltr"
               aria-label="رقم الوصل عند التاجر">
    </td>
    <td class="min-w-36 px-1 py-2">
        <input name="rows[{{ $i }}][notes]" value="{{ $old('notes') }}" class="{{ $cell }}" aria-label="ملاحظات">
    </td>
    <td class="px-2 py-3 text-center">
        <input type="checkbox" name="rows[{{ $i }}][exchange]" value="1" class="size-4 accent-[var(--brand)]"
               aria-label="استبدال أو استرجاع بضاعة" @checked($old('exchange'))>
    </td>
    <td class="px-2 py-3">
        <button type="button" class="text-bad-700 hover:underline" data-quick-remove aria-label="احذف الصفّ">×</button>
    </td>
</tr>
