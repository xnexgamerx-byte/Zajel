@extends('layouts.app')
@section('title', 'المحادثات')

@section('content')
<div class="mb-5">
    <h1 class="text-xl font-bold">المحادثات</h1>
    <p class="mt-1 text-sm text-ink-500">أسئلة التجّار للشركة لا لموظّفٍ بعينه: يراها كل مَن يردّ، وما ينتظرنا أوّلاً.</p>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <div class="mb-4 flex flex-wrap gap-2">
            @foreach (['waiting' => 'تنتظر ردّنا', 'open' => 'المفتوحة', 'closed' => 'المُغلقة', 'all' => 'الكل'] as $value => $label)
                <a href="{{ route('conversations.index', ['show' => $value]) }}"
                   class="chip {{ $filter === $value ? 'chip-info' : 'chip-mute' }}">
                    {{ $label }}
                    @isset($counts[$value])<span class="num">({{ number_format($counts[$value]) }})</span>@endisset
                </a>
            @endforeach
        </div>

        <section class="card overflow-hidden">
            @if ($conversations->isEmpty())
                <p class="p-10 text-center text-ink-500">
                    {{ $filter === 'waiting' ? 'لا سؤال ينتظر ردّاً.' : 'لا محادثات هنا.' }}
                </p>
            @else
                <ul class="divide-y divide-ink-100">
                    @foreach ($conversations as $conversation)
                        <li>
                            <a href="{{ route('conversations.show', $conversation) }}"
                               class="flex items-start justify-between gap-3 px-5 py-3.5 transition hover:bg-ink-50">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        @if ($conversation->staff_unread)
                                            <span class="h-2 w-2 shrink-0 rounded-full bg-[var(--brand)]" aria-label="غير مقروءة"></span>
                                        @endif
                                        <span class="truncate font-semibold">{{ $conversation->subject }}</span>
                                    </div>
                                    <p class="mt-0.5 text-sm text-ink-600">
                                        {{ $conversation->merchant?->business_name }}
                                        @if ($conversation->shipment)
                                            · وصل <span class="num">{{ $conversation->shipment->number }}</span>
                                        @endif
                                    </p>
                                </div>
                                <div class="shrink-0 text-end">
                                    @if ($conversation->awaitsUs())
                                        @php $hours = (int) $conversation->last_message_at->diffInHours(now()); @endphp
                                        {{-- سؤالٌ بلا جواب منذ يوم: التاجر يسأل الآن على واتساب غيرنا --}}
                                        <span class="chip {{ $hours >= 24 ? 'chip-bad' : ($hours >= 4 ? 'chip-warn' : 'chip-info') }}">ينتظر</span>
                                    @elseif (! $conversation->isOpen())
                                        <span class="chip chip-mute">مُغلقة</span>
                                    @endif
                                    <span class="num mt-1 block text-xs text-ink-500">{{ $conversation->last_message_at?->format('m-d H:i') }}</span>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <div class="p-4">{{ $conversations->links() }}</div>
            @endif
        </section>
    </div>

    <form method="POST" action="{{ route('conversations.store') }}" class="card h-fit space-y-4 p-5">
        @csrf
        <h2 class="card-title">راسِل تاجراً</h2>
        <div>
            <label class="field-label" for="merchant_id">التاجر</label>
            <select id="merchant_id" name="merchant_id" class="field-input" required>
                <option value="">اختر…</option>
                @foreach ($merchants as $merchant)
                    <option value="{{ $merchant->id }}" @selected((int) old('merchant_id') === $merchant->id)>{{ $merchant->business_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="field-label" for="subject">الموضوع</label>
            <input id="subject" name="subject" class="field-input" maxlength="160" required value="{{ old('subject') }}">
        </div>
        <div>
            <label class="field-label" for="shipment_number">رقم الوصل (اختياري)</label>
            <input id="shipment_number" name="shipment_number" class="field-input num" value="{{ old('shipment_number') }}">
        </div>
        <div>
            <label class="field-label" for="body">الرسالة</label>
            <textarea id="body" name="body" rows="4" class="field-input" maxlength="2000" required>{{ old('body') }}</textarea>
        </div>
        <button type="submit" class="btn-primary w-full">أرسل</button>
    </form>
</div>
@endsection
