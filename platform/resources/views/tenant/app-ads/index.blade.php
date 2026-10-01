@extends('layouts.app')
@section('title', 'إعلانات التطبيق')

@section('content')
<div class="mb-5">
    <h1 class="page-title">إعلانات التطبيق</h1>
    <p class="mt-1 text-sm text-ink-500">صورٌ تظهر أعلى بوابة التاجر أو تطبيق المندوب بترتيبها: عرضٌ، أو تغيير أسعار، أو فرعٌ جديد.</p>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <section class="card overflow-hidden">
            @forelse ($ads as $ad)
                <div class="flex flex-wrap items-center gap-4 border-b border-ink-100 p-4 last:border-b-0">
                    <img src="{{ route('app-ads.image', $ad) }}" alt="{{ $ad->title }}" class="h-20 w-36 rounded-lg object-cover {{ $ad->is_active ? '' : 'opacity-40' }}">
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold">{{ $ad->title }}</div>
                        <div class="text-xs text-ink-500">
                            {{ \App\Models\AppAd::SHOWN_TO[$ad->audience] ?? $ad->audience }}
                            @if ($ad->link_url) · <a href="{{ $ad->link_url }}" target="_blank" rel="noopener" class="text-[var(--brand)] hover:underline" dir="ltr">{{ \Illuminate\Support\Str::limit($ad->link_url, 40) }}</a>@endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('app-ads.update', $ad) }}" class="flex items-center gap-2">
                        @csrf @method('PUT')
                        <input name="sort_order" type="number" min="0" max="999" value="{{ $ad->sort_order }}" class="field-input num w-20 py-1" aria-label="الترتيب">
                        <input type="hidden" name="is_active" value="0">
                        <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="is_active" value="1" class="size-4 accent-[var(--brand)]" @checked($ad->is_active)> يظهر</label>
                        <button class="btn-ghost py-1 text-xs">احفظ</button>
                    </form>
                    <form method="POST" action="{{ route('app-ads.destroy', $ad) }}" onsubmit="return confirm('يُحذف الإعلان وصورته؟')">
                        @csrf @method('DELETE')
                        <button class="btn-ghost py-1 text-xs text-bad-700">احذف</button>
                    </form>
                </div>
            @empty
                <p class="p-10 text-center text-sm text-ink-500">لا إعلانات بعد.</p>
            @endforelse
        </section>
    </div>

    <form method="POST" action="{{ route('app-ads.store') }}" enctype="multipart/form-data" class="card h-fit space-y-4 p-5">
        @csrf
        <h2 class="text-sm font-bold">إعلان جديد</h2>
        <div>
            <label class="field-label" for="title">العنوان</label>
            <input id="title" name="title" maxlength="120" required class="field-input" value="{{ old('title') }}">
            @error('title') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="image">الصورة (JPG أو PNG أو WebP، حتى ٢ ميغابايت)</label>
            <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" required class="field-input">
            @error('image') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="link_url">رابط (اختياري)</label>
            <input id="link_url" name="link_url" type="url" dir="ltr" class="field-input" placeholder="https://" value="{{ old('link_url') }}">
            @error('link_url') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="field-label" for="audience">لمن</label>
                <select id="audience" name="audience" class="field-input">
                    @foreach (\App\Models\AppAd::AUDIENCES as $value => $label)
                        <option value="{{ $value }}" @selected(old('audience', 'merchants') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="sort_order">الترتيب</label>
                <input id="sort_order" name="sort_order" type="number" min="0" max="999" class="field-input num" value="{{ old('sort_order', 0) }}">
            </div>
        </div>
        <button type="submit" class="btn-primary w-full">أضف الإعلان</button>
    </form>
</div>
@endsection
