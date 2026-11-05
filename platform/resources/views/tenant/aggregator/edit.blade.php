@extends('layouts.app')
@section('title', 'ربط الشركة الوسيطة')

{{--
  الربط الآلي مع نظام الشركة الوسيطة (docs/plan/104): الحساب والتاجر، والمندوب الوسيط عندنا،
  والبادئة، ومفتاح الـ Webhook، وربط الحالات. المناطق والسجلّ في صفحتيهما.
--}}
@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">ربط الشركة الوسيطة</h1>
        <p class="mt-1 text-sm text-ink-500">
            ما يُسند للمندوب الوسيط يُنشأ في نظامهم برقم وصلنا، وكل تغيّرٍ عندهم يصلنا فوراً ويُطبَّق بقواعد المندوب.
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('aggregator.areas') }}" class="btn-ghost"><x-icon name="pin" class="size-5"/> ربط المناطق
            @if ($unmapped)<span class="nav-badge">{{ $unmapped }}</span>@endif</a>
        <a href="{{ route('aggregator.log') }}" class="btn-ghost"><x-icon name="clipboard" class="size-5"/> السجلّ
            @if ($failing)<span class="nav-badge">{{ $failing }}</span>@endif</a>
        {{-- أيقرأ مندوبهم كلّ طرد (docs/plan/121) --}}
        <a href="{{ route('aggregator.review') }}" class="btn-ghost"><x-icon name="qr" class="size-5"/> مراجعة المسح</a>
    </div>
</div>

<div class="mb-5 flex flex-wrap gap-2 text-sm">
    <span class="chip {{ $link->sends_orders ? 'chip-ok' : 'chip-mute' }}">{{ $link->sends_orders ? 'الإرسال مفتوح' : 'الإرسال مغلق' }}</span>
    <span class="chip {{ $link->merchant_id ? 'chip-ok' : 'chip-warn' }}">{{ $link->merchant_id ? 'التاجر: '.$link->merchant_name : 'لم يُختر التاجر' }}</span>
    <span class="chip {{ $link->webhook_token_hash ? 'chip-ok' : 'chip-warn' }}">{{ $link->webhook_token_hash ? 'مفتاح الـ Webhook جاهز' : 'لا مفتاح Webhook بعد' }}</span>
    @if ($failing)<a href="{{ route('aggregator.log') }}" class="chip chip-bad">{{ $failing }} لم يُرسل</a>@endif
</div>

{{--
  هل تُقرأ تحديثاتهم؟ (docs/plan/107): آخر ما وصل، وآخر ما رُفض ولماذا، وما جرى لتحديثات الأسبوع. يُعرف منها
  أين الخلل: مفتاحٌ لا يطابق، أو شحناتٌ ليست مع مندوب الربط، أو مجدوِلٌ لا يعمل.
--}}
@php
    $week = $health['week'];
    $rejectedLast = $link->hook_rejected_at && (! $link->hook_last_at || $link->hook_rejected_at->gt($link->hook_last_at));
@endphp
<section class="card mb-5 p-5" aria-labelledby="health-title">
    <h2 id="health-title" class="card-title">استلام تحديثاتهم</h2>
    <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <dt class="text-ink-500">آخر تحديثٍ وصل</dt>
            <dd class="font-semibold {{ $link->hook_last_at ? '' : 'text-warn-700' }}">
                {{ $link->hook_last_at ? $link->hook_last_at->diffForHumans() : 'لم يصل أيّ تحديثٍ بعد' }}
            </dd>
        </div>
        <div>
            <dt class="text-ink-500">آخر طلبٍ رُفض</dt>
            <dd class="font-semibold {{ $rejectedLast ? 'text-bad-700' : '' }}">
                @if ($link->hook_rejected_at)
                    {{ $link->hook_rejected_at->diffForHumans() }} — {{ $link->hook_rejected_reason }}
                @else
                    لا شيء
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-ink-500">مع المندوب الوسيط الآن</dt>
            <dd class="font-semibold"><span class="num">{{ $health['active'] }}</span> شحنة
                @if ($health['unsent'])<span class="block text-xs text-warn-700"><span class="num">{{ $health['unsent'] }}</span> منها بلا رقمٍ عندهم بعد</span>@endif
            </dd>
        </div>
        <div>
            <dt class="text-ink-500">تحديثات آخر ٧ أيام</dt>
            <dd class="flex flex-wrap gap-1">
                @foreach (\App\Models\AggregatorEvent::RESULTS as $key => $label)
                    @if (($week[$key] ?? 0) > 0)
                        <a href="{{ route('aggregator.log', ['result' => $key]) }}" class="chip {{ ['applied' => 'chip-ok', 'waiting' => 'chip-warn', 'unmatched' => 'chip-warn', 'error' => 'chip-bad', 'pending' => 'chip-info'][$key] ?? 'chip-mute' }}">{{ $label }} <span class="num">{{ $week[$key] }}</span></a>
                    @endif
                @endforeach
                @if (! array_sum($week))<span class="text-ink-500">لا شيء</span>@endif
            </dd>
        </div>
    </dl>
    @if (! $link->webhook_token_hash)
        <p class="alert alert-warn mt-3 text-sm"><span>لا مفتاح بعد: كلّ تحديثٍ منهم يُرفض. ولّد المفتاح من بطاقة الـ Webhook وأعطهم إيّاه بصيغته.</span></p>
    @elseif ($rejectedLast)
        <p class="alert alert-warn mt-3 text-sm"><span>آخر ما أرسلوه رُفض: {{ $link->hook_rejected_reason }}. تأكّد أنّ ما عندهم هو المفتاح الحاليّ (ينتهي بـ <code dir="ltr">…{{ $link->webhook_token_last4 }}</code>) في الترويسة «{{ $link->webhook_header }}»، بالصيغة التي ظهرت حين وُلّد.</span></p>
    @endif
    @if ($week['unmatched'] ?? 0)
        <p class="alert alert-warn mt-3 text-sm"><span>تحديثاتٌ لم تُعرف شحنتها: في السجلّ سبب كلٍّ منها (الشحنة مع مندوبٍ غير مندوب الربط، أو رقمٌ لا نعرفه). أصلِحه ثم «أعد التطبيق».</span></p>
    @endif
    @if ($health['stuck'])
        <p class="alert alert-warn mt-3 text-sm"><span><span class="num">{{ $health['stuck'] }}</span> تحديثاً ينتظر التطبيق أكثر من دقيقتين: المجدوِل لا يعمل على الخادم؟</span></p>
    @endif
</section>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <form method="POST" action="{{ route('aggregator.update') }}" class="card space-y-5 p-5 lg:col-span-2">
        @csrf
        @method('PUT')
        <h2 class="card-title">الحساب والإرسال</h2>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="field-label" for="base_url">عنوان نظامهم</label>
                <input id="base_url" name="base_url" dir="ltr" class="field-input" required value="{{ old('base_url', $link->base_url) }}">
                @error('base_url') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="username">اسم المستخدم (حساب الشركة عندهم)</label>
                <input id="username" name="username" dir="ltr" autocomplete="off" class="field-input" value="{{ old('username', $link->username) }}">
            </div>
            <div>
                <label class="field-label" for="password">كلمة المرور</label>
                <input id="password" type="password" name="password" autocomplete="new-password" class="field-input"
                       placeholder="{{ $link->password ? 'محفوظة — اكتب لتغييرها' : '' }}">
                <p class="field-hint">تُحفظ مشفّرة، ولا تُعرض.</p>
            </div>
            <div>
                <label class="field-label" for="courier_id">المندوب الوسيط عندنا</label>
                <select id="courier_id" name="courier_id" class="field-input">
                    <option value="">—</option>
                    @foreach ($couriers as $c)
                        <option value="{{ $c->id }}" @selected(old('courier_id', $link->courier_id) == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
                <p class="field-hint">ما يُسند له يُرسل لهم. المندوب يُعلَّم «شركة وسيطة» من بطاقته.</p>
                @error('courier_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="order_prefix">البادئة المسجّلة عندهم</label>
                <input id="order_prefix" name="order_prefix" dir="ltr" class="field-input" placeholder="ABC_" value="{{ old('order_prefix', $link->order_prefix) }}">
                <p class="field-hint">حروفٌ إنكليزية كبيرة و«_». رقم الطلب عندهم = البادئة ثم رقم الوصل.</p>
                @error('order_prefix') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="package_size_id">حجم الطرد عندهم</label>
                @if ($sizes)
                    <select id="package_size_id" name="package_size_id" class="field-input">
                        <option value="">—</option>
                        @foreach ($sizes as $id => $size)
                            <option value="{{ $id }}" @selected(old('package_size_id', $link->package_size_id) == $id)>{{ $size }}</option>
                        @endforeach
                    </select>
                @else
                    <input id="package_size_id" name="package_size_id" class="field-input" value="{{ old('package_size_id', $link->package_size_id) }}" placeholder="اتّصل بنظامهم لتظهر الأحجام">
                @endif
            </div>
            <div>
                <label class="field-label" for="webhook_header">اسم ترويسة المفتاح</label>
                <input id="webhook_header" name="webhook_header" dir="ltr" class="field-input" required value="{{ old('webhook_header', $link->webhook_header) }}">
                @error('webhook_header') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <label class="flex items-start gap-3 rounded-lg px-3 py-2.5 text-sm ring-1 ring-ink-200">
            <input type="checkbox" name="sends_orders" value="1" class="mt-0.5 size-4 shrink-0" @checked(old('sends_orders', $link->sends_orders))>
            <span><b class="block">أرسل الشحنات لنظامهم تلقائياً</b>
                <span class="text-xs text-ink-500">حين تُسند للمندوب الوسيط تُنشأ عندهم خلال دقيقة. ما لا يُرسل يظهر في السجلّ بسببه.</span></span>
        </label>

        <button type="submit" class="btn-primary">احفظ</button>
    </form>

    <div class="space-y-5">
        <section class="card space-y-3 p-5">
            <h2 class="card-title">الاتصال والتاجر</h2>
            <p class="card-hint">
                يدخل بالحساب المحفوظ، ويجلب التجّار والمدن والحالات وأحجام الطرود.
                @unless ($link->username && $link->password)
                    <b class="text-warn-700">اكتب اسم المستخدم وكلمة المرور واضغط «احفظ» أوّلاً.</b>
                @endunless
            </p>
            <form method="POST" action="{{ route('aggregator.connect') }}">
                @csrf
                <button type="submit" class="btn-ghost w-full justify-center"><x-icon name="bolt" class="size-5"/> اتّصل بنظامهم</button>
            </form>
            @if ($merchants)
                <form method="POST" action="{{ route('aggregator.merchant') }}" class="space-y-2">
                    @csrf
                    <label class="field-label" for="merchant_id">التاجر الذي تُرسل باسمه</label>
                    <select id="merchant_id" name="merchant_id" class="field-input">
                        @foreach ($merchants as $m)
                            <option value="{{ $m['id'] }}" @selected($link->merchant_id == $m['id'])>{{ $m['name'] }} ({{ $m['username'] }})</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn-ghost w-full justify-center">اختر هذا التاجر</button>
                </form>
            @endif
        </section>

        <section class="card space-y-3 p-5">
            <h2 class="card-title">الـ Webhook</h2>
            <p class="card-hint">أعطهم هذا العنوان والمفتاح ليرسلوا تحديثات الطلبات.</p>
            <div>
                <span class="field-label">العنوان</span>
                <code class="block break-all rounded-lg bg-ink-50 px-3 py-2 text-xs" dir="ltr">{{ route('api.aggregator.webhook') }}</code>
                <button type="button" class="btn-ghost mt-2 py-1" data-copy-text="{{ route('api.aggregator.webhook') }}">نسخ العنوان</button>
            </div>
            @if ($hook)
                <div class="alert alert-warn">
                    <span class="field-label">المفتاح بصيغتهم (يظهر الآن فقط)</span>
                    <code class="block break-all text-xs" dir="ltr">{{ $hook }}</code>
                    <button type="button" class="btn-ghost mt-2 py-1" data-copy-text="{{ $hook }}">نسخ المفتاح</button>
                </div>
            @elseif ($link->webhook_token_hash)
                <p class="text-sm">المفتاح الحاليّ ينتهي بـ <code dir="ltr">…{{ $link->webhook_token_last4 }}</code></p>
            @endif
            <form method="POST" action="{{ route('aggregator.hook') }}" data-confirm="{{ $link->webhook_token_hash ? 'المفتاح الحاليّ يتوقّف فوراً حتى تعطيهم الجديد. متأكّد؟' : 'يُولَّد مفتاحٌ جديد.' }}">
                @csrf
                <button type="submit" class="btn-ghost w-full justify-center"><x-icon name="lock" class="size-5"/> {{ $link->webhook_token_hash ? 'ولّد مفتاحاً جديداً' : 'ولّد المفتاح' }}</button>
            </form>
        </section>
    </div>
</div>

<form method="POST" action="{{ route('aggregator.statuses') }}" class="card mt-5 p-5">
    @csrf
    @method('PUT')
    <h2 class="card-title">ربط حالاتهم بحالاتنا</h2>
    <p class="card-hint">
        ما يُفعل بالشحنة عندنا حين تصل حالةٌ منهم. «واصل» يأتي أيضاً بعلامة التسليم أيّاً كانت الحالة، وتغيّر السعر
        يذهب تذكرةً للكول سنتر ولا يغيّر المبلغ. سبب «لم يُسلَّم» و«مؤجّل» و«راجع» هو نصّ حالتهم نفسه، وما كتبه مندوبهم
        يصل ملاحظةً للكول سنتر.
    </p>
    <details class="mt-3 rounded-lg px-3 py-2 ring-1 ring-ink-200">
        <summary class="cursor-pointer text-sm font-semibold">ماذا يفعل كلّ خيار؟</summary>
        <dl class="mt-2 space-y-2 text-sm">
            @foreach (\App\Models\AggregatorLink::ACTIONS as $key => $label)
                <div><dt class="font-bold">{{ $label }}</dt><dd class="text-ink-700">{{ \App\Models\AggregatorLink::ACTION_HINTS[$key] }}</dd></div>
            @endforeach
        </dl>
    </details>
    @if ($statuses)
        {{-- لا سبب يُختار (docs/plan/107): سبب «لم يُسلَّم» و«مؤجّل» و«راجع» نصّ حالتهم، وما كتبه مندوبهم ملاحظةٌ للكول سنتر --}}
        <div class="mt-4 grid grid-cols-1 gap-x-6 gap-y-2 md:grid-cols-2">
            @foreach ($statuses as $id => $text)
                <label class="flex items-center justify-between gap-3 border-b border-ink-100 py-1.5 text-sm">
                    <span><span class="num text-ink-500">{{ $id }}</span> · {{ $text }}</span>
                    <select name="map[{{ $id }}]" class="field-input w-52 py-1.5">
                        @foreach (\App\Models\AggregatorLink::ACTIONS as $key => $label)
                            <option value="{{ $key }}" @selected($link->actionFor((string) $id) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            @endforeach
        </div>
        <button type="submit" class="btn-primary mt-4">احفظ ربط الحالات</button>
    @else
        <p class="mt-3 text-sm text-ink-500">اتّصل بنظامهم واختر التاجر لتظهر حالاتهم هنا.</p>
    @endif
</form>
@endsection
