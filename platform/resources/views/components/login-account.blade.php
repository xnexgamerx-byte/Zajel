@props([
    'account' => null,   // حسابه القائم: يُغيَّر اسمه وكلمة مروره (ChangeLogin)
    'toggle',            // معرّف حقول الإنشاء، تُظهرها الخانة
    'app',               // «تطبيق المندوبين» أو «بوابة التجّار»
    'createLabel',
    'createHint',
    'passwordHint' => null,
])

<section class="card p-5">
    <h2 class="mb-4 text-sm font-bold">حساب الدخول</h2>

    @if ($account)
        <p class="mb-4 text-xs text-ink-500">
            يدخل {{ $app }} بهذا الاسم. غيّره، أو اكتب كلمة مرورٍ جديدة — وإن تغيّرت كلمة المرور خرج من أجهزته كلّها.
        </p>
        <div class="space-y-4">
            <div>
                <label class="field-label" for="username">اسم المستخدم</label>
                <input id="username" name="username" class="field-input text-left" dir="ltr"
                       value="{{ old('username', $account->username) }}" autocomplete="off" autocapitalize="none" spellcheck="false">
                <p class="mt-1 text-xs text-ink-500">حروفٌ إنجليزية وأرقام و<span dir="ltr">. _ -</span>.</p>
                @error('username') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="password">كلمة مرورٍ جديدة</label>
                <input id="password" name="password" type="text" class="field-input text-left" dir="ltr"
                       autocomplete="new-password" placeholder="اتركها فارغة لعدم التغيير">
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>
    @else
        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="create_login" value="1" @checked(old('create_login'))
                   class="mt-0.5 rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500"
                   data-toggle="{{ $toggle }}">
            <span>
                {{ $createLabel }}
                <span class="mt-0.5 block text-xs text-ink-500">{{ $createHint }}</span>
            </span>
        </label>
        @error('create_login') <p class="field-error">{{ $message }}</p> @enderror

        <div class="mt-4 space-y-4" id="{{ $toggle }}" hidden>
            <div>
                <label class="field-label" for="username">اسم المستخدم</label>
                <input id="username" name="username" class="field-input text-left" dir="ltr"
                       value="{{ old('username') }}" autocomplete="off" autocapitalize="none"
                       spellcheck="false" placeholder="فارغاً: رقم هاتفه">
                @error('username') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="password">كلمة المرور</label>
                <input id="password" name="password" type="text" class="field-input text-left" dir="ltr"
                       @if ($passwordHint) placeholder="{{ $passwordHint }}" @endif>
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>
    @endif
</section>
