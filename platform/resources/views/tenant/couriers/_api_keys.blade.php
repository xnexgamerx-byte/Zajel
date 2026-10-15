{{-- مفاتيح ربط الشركة الوسيطة (docs/plan/64): يحدّث بها نظامها حالات شحناتها عندنا --}}
<section class="card p-5">
    <h2 class="text-sm font-bold">ربط نظام الشركة الوسيطة</h2>
    <p class="mt-1 text-xs text-ink-500">
        مفتاحٌ يعطى لنظامهم: يقرأ الشحنات المسندة لهم ويسجّل «واصل» أو «لم يُسلَّم» أو «مؤجّل» وموقعها.
        لا يرى التجّار ولا الأجور، ولا يغيّر مبلغاً ولا عنواناً.
    </p>

    @if (session('api_token'))
        <div class="mt-4 rounded-xl border border-warn-200 bg-warn-50 p-3">
            <div class="text-xs font-bold text-warn-700">انسخ المفتاح الآن — لا يظهر مرّةً ثانية</div>
            <code class="mt-2 block break-all rounded bg-white p-2 font-mono text-xs" dir="ltr">{{ session('api_token') }}</code>
            <button type="button" class="btn-ghost mt-2 py-1" data-copy-text="{{ session('api_token') }}">نسخ المفتاح</button>
        </div>
    @endif

    @can('settings.company')
        <div class="mt-4 space-y-2">
            @forelse ($apiKeys as $key)
                @php($dead = $key->isDead())
                <div class="flex items-start justify-between gap-2 rounded-lg border border-ink-100 p-2.5 {{ $dead ? 'opacity-60' : '' }}">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold">{{ $key->name }}
                            <span class="font-mono text-xs text-ink-500" dir="ltr">…{{ $key->token_hint }}</span>
                        </div>
                        <div class="text-xs text-ink-500">
                            {{ collect($key->abilities)->map(fn ($a) => \App\Models\CourierApiKey::ABILITIES[$a] ?? $a)->join('، ') }}
                            · ينتهي {{ $key->expires_at->format('Y-m-d') }}
                            @if ($key->allowed_ips) · من <span dir="ltr">{{ implode(', ', $key->allowed_ips) }}</span> @endif
                        </div>
                        <div class="text-xs text-ink-500">آخر استعمال: {{ $key->last_used_at?->diffForHumans() ?? 'لم يُستعمل' }}</div>
                    </div>
                    @if ($dead)
                        <span class="chip chip-mute">{{ $key->revoked_at ? 'ملغى' : 'منتهٍ' }}</span>
                    @else
                        <form method="POST" action="{{ route('couriers.api-keys.revoke', [$courier, $key]) }}">
                            @csrf
                            <button class="text-xs font-semibold text-bad-700" data-confirm="نلغي المفتاح؟ يُرفض نظامهم من الطلب التالي.">ألغِ</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="text-sm text-ink-500">لا مفاتيح بعد.</p>
            @endforelse
        </div>

        <form method="POST" action="{{ route('couriers.api-keys.store', $courier) }}" class="mt-4 space-y-3 border-t border-ink-100 pt-4">
            @csrf
            <div>
                <label class="field-label" for="key_name">اسم المفتاح</label>
                <input id="key_name" name="name" class="field-input" required maxlength="120" placeholder="نظامهم الرئيسي" value="{{ old('name') }}">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <fieldset>
                <legend class="field-label">الصلاحيات</legend>
                @foreach (\App\Models\CourierApiKey::ABILITIES as $ability => $label)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="abilities[]" value="{{ $ability }}" @checked(in_array($ability, old('abilities', array_keys(\App\Models\CourierApiKey::ABILITIES)), true))>
                        {{ $label }}
                    </label>
                @endforeach
                @error('abilities') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>
            <div>
                <label class="field-label" for="key_expires">ينتهي في</label>
                <input id="key_expires" name="expires_at" type="date" class="field-input" required dir="ltr"
                       value="{{ old('expires_at', now()->addYear()->toDateString()) }}">
                @error('expires_at') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="key_ips">عناوين IP المسموحة (اختياري)</label>
                <textarea id="key_ips" name="allowed_ips" rows="2" class="field-input font-mono text-xs" dir="ltr"
                          placeholder="203.0.113.10, 203.0.113.11">{{ old('allowed_ips') }}</textarea>
                <p class="mt-1 text-xs text-ink-500">فارغ = من أيّ عنوان. الأأمن أن تكتب عنوان خادمهم.</p>
                @error('allowed_ips') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button class="btn-primary w-full">أنشئ مفتاحاً</button>
        </form>
    @endcan
</section>
