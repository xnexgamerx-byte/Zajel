@extends('layouts.app')
@section('title', 'محادثة المناديب')

@section('content')
<div class="mb-5">
    <h1 class="page-title">محادثة المناديب</h1>
    <p class="mt-1 text-sm text-ink-500">
        الكول سنتر والمندوب في محادثةٍ واحدة لكل مندوب: «اتّصل بالزبون»، «الزبون لا يردّ» — وكل رسالةٍ تحمل رقم شحنتها إن كانت عنها.
    </p>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <section class="card h-fit overflow-hidden">
        <form method="GET" action="{{ route('courier-chat.index') }}" role="search" class="flex gap-2 border-b border-ink-100 p-3">
            <input name="q" type="search" value="{{ $q }}" class="field-input py-1.5" placeholder="ابحث باسم المندوب أو هاتفه">
            <button type="submit" class="btn-ghost px-3">ابحث</button>
        </form>
        <ul class="max-h-[32rem] divide-y divide-ink-100 overflow-y-auto">
            @forelse ($threads as $thread)
                <li>
                    <a href="{{ route('courier-chat.index', ['courier' => $thread->courier_id]) }}"
                       @class(['flex items-center justify-between gap-3 px-4 py-3 text-sm hover:bg-primary-50/50', 'bg-primary-50/60' => $courier?->id === $thread->courier_id])>
                        <span class="min-w-0">
                            <span class="block truncate {{ $thread->staff_unread ? 'font-bold' : 'font-medium' }}">{{ $thread->courier?->name }}</span>
                            <span class="text-xs text-ink-500">{{ $thread->last_author === 'courier' ? 'كتب المندوب' : 'كتبنا' }} ·
                                <span class="num">{{ $thread->last_message_at?->format('m-d H:i') }}</span></span>
                        </span>
                        @if ($thread->staff_unread)
                            <span class="chip chip-warn shrink-0">ينتظر ردّنا</span>
                        @endif
                    </a>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-sm text-ink-500">لا محادثة بعد. ابدأ واحدة من اليسار.</li>
            @endforelse
        </ul>
    </section>

    <section class="card flex flex-col p-5 lg:col-span-2">
        @if ($courier)
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 pb-3">
                <div>
                    <h2 class="card-title">{{ $courier->name }}</h2>
                    <p class="card-hint num">{{ $courier->phone }}</p>
                </div>
                <a href="{{ route('courier-manifests.show', $courier) }}" class="btn-ghost text-sm">كشفه الآن</a>
            </div>

            <ol class="mb-4 max-h-[28rem] space-y-3 overflow-y-auto" aria-label="الرسائل">
                @forelse ($messages as $message)
                    @php $mine = $message->author === 'staff'; @endphp
                    <li class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[85%] rounded-2xl px-4 py-2.5 {{ $mine ? 'bg-[var(--brand)] text-white' : 'border border-ink-200 bg-white' }}">
                            @if ($message->shipment)
                                <a href="{{ route('shipments.show', $message->shipment) }}"
                                   class="mb-1 inline-block rounded-md px-2 py-0.5 text-xs {{ $mine ? 'bg-white/15' : 'bg-ink-50' }}">
                                    الشحنة <span class="num">{{ $message->shipment->number }}</span>
                                </a>
                            @endif
                            <p class="whitespace-pre-line text-sm leading-relaxed">{{ $message->body }}</p>
                            <p class="mt-1 text-[11px] {{ $mine ? 'text-white/75' : 'text-ink-400' }}">
                                {{ $message->author_name }} · <span class="num">{{ $message->created_at->format('m-d H:i') }}</span>
                            </p>
                        </div>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-ink-500">لا رسالة بعد. اكتب الأولى.</li>
                @endforelse
            </ol>
        @endif

        <form method="POST" action="{{ route('courier-chat.send') }}" class="mt-auto space-y-3">
            @csrf
            @if ($courier)
                <input type="hidden" name="courier_id" value="{{ $courier->id }}">
            @else
                <div>
                    <label class="field-label" for="courier_id">المندوب</label>
                    <select id="courier_id" name="courier_id" class="field-input" data-searchable required>
                        <option value="">اختر</option>
                        @foreach ($couriers as $item)
                            <option value="{{ $item->id }}" @selected((int) old('courier_id') === $item->id)>{{ $item->name }} ({{ $item->code }})</option>
                        @endforeach
                    </select>
                    @error('courier_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endif
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                <div class="sm:col-span-3">
                    <label class="field-label" for="body">الرسالة</label>
                    <textarea id="body" name="body" rows="2" maxlength="2000" required class="field-input"
                              placeholder="مثلاً: الزبون اتّصل، يقول إنه في البيت بعد الخامسة">{{ old('body') }}</textarea>
                    @error('body') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="shipment">رقم الشحنة (اختياري)</label>
                    <input id="shipment" name="shipment" type="text" maxlength="40" class="field-input num" value="{{ old('shipment', $shipment) }}">
                    @error('shipment') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <button type="submit" class="btn-primary">أرسل</button>
        </form>
    </section>
</div>
@endsection
