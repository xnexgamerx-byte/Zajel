@props(['action', 'templateUrl', 'columns', 'required', 'merchants' => null])

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <form method="POST" action="{{ $action }}" enctype="multipart/form-data"
          class="card space-y-4 p-5 lg:col-span-2">
        @csrf

        @if ($merchants)
            <div>
                <label class="field-label" for="merchant_id">التاجر <span class="text-bad-700">*</span></label>
                <select id="merchant_id" name="merchant_id" class="field-input" required>
                    <option value="">اختر التاجر</option>
                    @foreach ($merchants as $merchant)
                        <option value="{{ $merchant->id }}" @selected((int) old('merchant_id') === $merchant->id)>
                            {{ $merchant->business_name }}
                        </option>
                    @endforeach
                </select>
                @error('merchant_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        @endif

        <div>
            <span class="field-label">ملف الشحنات <span class="text-bad-700">*</span></span>

            {{-- المُدخَل شفّاف فوق البطاقة لا مخفيّ: المتصفّح يرفض التحقّق من حقل
                 مطلوب لا يستطيع إظهاره، فتُبتلع رسالة «اختر ملفاً» بلا أثر. --}}
            <label for="file" class="group relative flex cursor-pointer items-center gap-3 rounded-lg border-2
                                     border-dashed border-ink-300 bg-ink-50 px-4 py-6 transition
                                     focus-within:border-brand hover:border-brand hover:bg-white">
                <svg class="size-9 shrink-0 text-ink-400 transition group-hover:text-brand" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 16.5V6m0 0L8.25 9.75M12 6l3.75 3.75M4.5 16.5v1.875A1.125 1.125 0 0 0 5.625 19.5h12.75a1.125 1.125 0 0 0 1.125-1.125V16.5"/>
                </svg>
                <span class="min-w-0">
                    <span class="block font-semibold text-ink-800" data-file-name>اختر ملفاً من جهازك</span>
                    <span class="block text-sm text-ink-500">Excel أو CSV، حتى 5 ميغابايت.</span>
                </span>
                <input id="file" name="file" type="file" accept=".xlsx,.xls,.csv" required
                       class="absolute inset-0 size-full cursor-pointer opacity-0"
                       onchange="this.closest('label').querySelector('[data-file-name]').textContent = this.files[0]?.name ?? 'اختر ملفاً من جهازك'">
            </label>

            @error('file') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="rounded-lg border border-ink-200 bg-ink-50 px-4 py-3 text-sm text-ink-600">
            لا يُنشأ شيء عند الرفع. تُعرض معاينة أولاً، وأنت تؤكّد.
        </div>

        <button type="submit" class="btn-primary w-full sm:w-auto">ارفع وعاين</button>
    </form>

    <section class="card p-5">
        <h2 class="card-title">أعمدة الملف</h2>
        <p class="card-hint mb-3">ترتيب الأعمدة غير مهم — يُقرأ العنوان لا الموضع.</p>

        <ul class="space-y-1 text-sm">
            @foreach ($columns as $field => $label)
                <li class="flex items-center gap-2">
                    <span class="{{ in_array($field, $required, true) ? 'font-semibold text-ink-900' : 'text-ink-600' }}">
                        {{ $label }}
                    </span>
                    @if (in_array($field, $required, true))
                        <span class="text-xs text-bad-700">إلزامي</span>
                    @endif
                </li>
            @endforeach
        </ul>

        <a href="{{ $templateUrl }}" class="btn-ghost mt-4 w-full">نزّل القالب</a>

        <p class="field-hint mt-3">
            يُقبل الهاتف بصيغ 07xx و+9647xx، والأرقام العربية، والمحافظة باسمها أو باسم مركزها.
        </p>
    </section>
</div>
