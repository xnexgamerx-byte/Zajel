{{-- صندوق القبض: فيه يدخل نقد الكشوف المُقفَلة من الوجبة، ومنه وإليه حركات الفروق --}}
<div class="min-w-48">
    <label class="field-label" for="cash_box_id">صندوق القبض</label>
    <select id="cash_box_id" name="cash_box_id" class="field-input">
        @foreach ($boxes as $box)
            <option value="{{ $box->id }}" @selected((int) old('cash_box_id', $defaultBox) === $box->id)>{{ $box->name }}</option>
        @endforeach
    </select>
    @error('cash_box_id') <p class="field-error">{{ $message }}</p> @enderror
</div>
