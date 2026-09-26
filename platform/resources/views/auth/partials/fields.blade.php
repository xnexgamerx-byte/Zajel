{{-- حقلا الدخول، لدخول الشركات ولوحة المنصّة معاً. الخطأ تحت حقله، والاسم يبقى بعده. --}}
<div class="field">
    <label for="username">اسم المستخدم</label>
    <div class="input-wrap @error('username') is-invalid @enderror">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
            <circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>
        </svg>
        <input id="username" name="username" type="text" value="{{ old('username') }}"
               placeholder="أدخل اسم المستخدم" autocomplete="username" autocapitalize="none"
               spellcheck="false" required autofocus
               @error('username') aria-invalid="true" aria-describedby="username-error" @enderror>
    </div>
    @error('username') <p class="field-error" id="username-error">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label for="password">كلمة المرور</label>
    <div class="input-wrap @error('password') is-invalid @enderror">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
            <rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3"/><path d="M12 14v3"/>
        </svg>
        <input id="password" name="password" type="password" placeholder="أدخل كلمة المرور"
               autocomplete="current-password" required
               @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
    </div>
    @error('password') <p class="field-error" id="password-error">{{ $message }}</p> @enderror
</div>
