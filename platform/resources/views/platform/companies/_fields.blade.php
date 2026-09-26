{{-- حقول الشركة في تسجيلها وتعديلها. والنطاق الفرعي يُختار مرّةً عند التسجيل --}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="field-label" for="name">الاسم بالعربي <span class="text-red-500">*</span></label>
        <input id="name" name="name" class="field-input" required maxlength="160"
               value="{{ old('name', $company->name) }}" placeholder="مثال: البرق للتوصيل">
        @error('name') <p class="field-error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="field-label" for="name_en">الاسم بالإنجليزي</label>
        <input id="name_en" name="name_en" class="field-input text-left" dir="ltr" maxlength="160"
               value="{{ old('name_en', $company->name_en) }}">
        @error('name_en') <p class="field-error">{{ $message }}</p> @enderror
    </div>

    <div class="sm:col-span-2">
        @if ($company->exists)
            <span class="field-label">النطاق الفرعي</span>
            <p class="font-mono text-sm text-ink-700" dir="ltr">{{ $company->slug }}.{{ config('zajel.tenant_domain') }}</p>
            <p class="mt-1 text-xs text-ink-500">
                لا يتغيّر: عليه تتصل تطبيقات الشركة، ورابط التتبّع مطبوعٌ به على ملصقاتها.
            </p>
        @else
            <label class="field-label" for="slug">النطاق الفرعي <span class="text-red-500">*</span></label>
            <div class="flex items-center gap-2" dir="ltr">
                <input id="slug" name="slug" class="field-input text-left" required
                       value="{{ old('slug') }}" placeholder="barq" pattern="[a-z0-9]+(-[a-z0-9]+)*">
                <span class="shrink-0 text-sm text-ink-500">.{{ config('zajel.tenant_domain') }}</span>
            </div>
            <p class="mt-1 text-xs text-ink-500">
                عنوان نظام الشركة. لا يتغيّر بعد التسجيل لأن التطبيقات تتصل به.
            </p>
            @error('slug') <p class="field-error">{{ $message }}</p> @enderror
        @endif
    </div>

    <div>
        <label class="field-label" for="phone">هاتف الشركة</label>
        <input id="phone" name="phone" class="field-input text-left" dir="ltr" inputmode="tel"
               placeholder="07xxxxxxxxx" value="{{ old('phone', $company->phone) }}">
        @error('phone') <p class="field-error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="field-label" for="email">بريد الشركة</label>
        <input id="email" name="email" type="email" class="field-input text-left" dir="ltr"
               value="{{ old('email', $company->email) }}">
        @error('email') <p class="field-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="field-label" for="governorate_id">المحافظة</label>
        <select id="governorate_id" name="governorate_id" class="field-input">
            <option value="">اختر</option>
            @foreach ($governorates as $gov)
                <option value="{{ $gov->id }}" @selected((int) old('governorate_id', $company->governorate_id) === $gov->id)>
                    {{ $gov->name_ar }}
                </option>
            @endforeach
        </select>
        @error('governorate_id') <p class="field-error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="field-label" for="primary_color">لون العلامة</label>
        <input id="primary_color" name="primary_color" type="color"
               class="field-input h-10 p-1" value="{{ old('primary_color', $company->primary_color ?: '#0d9488') }}">
        <p class="mt-1 text-xs text-ink-500">يظهر في لوحة الشركة وتطبيقاتها.</p>
        @error('primary_color') <p class="field-error">{{ $message }}</p> @enderror
    </div>

    <div class="sm:col-span-2">
        <label class="field-label" for="address">العنوان</label>
        <input id="address" name="address" class="field-input" maxlength="255"
               placeholder="مثال: بغداد، الكرادة، قرب ساحة الواثق" value="{{ old('address', $company->address) }}">
        @error('address') <p class="field-error">{{ $message }}</p> @enderror
    </div>
</div>
