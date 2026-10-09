@extends('layouts.app')
@section('title', 'مراسلة الموظفين')

@section('content')
<div class="mb-5">
    <h1 class="page-title">مراسلة الموظفين</h1>
    <p class="mt-1 text-sm text-ink-500">
        الموظّفون وجنب كلٍّ منهم زرّ واتساب. وللمراسلة من داخل النظام — لموظّفٍ أو لقسمٍ كلّه — تبويب «داخل النظام».
    </p>
</div>

@php $unreadCount = count($unread); @endphp
<nav class="tab-nav mb-4" aria-label="مراسلة الموظفين">
    <a href="{{ route('staff-chat.index', array_filter(['shipment' => $shipment])) }}" @class(['tab-link', 'tab-link-active' => $tab === 'people'])>الموظفون — واتساب</a>
    <a href="{{ route('staff-chat.index', array_filter(['tab' => 'chat', 'shipment' => $shipment])) }}" @class(['tab-link', 'tab-link-active' => $tab === 'chat'])>
        داخل النظام @if ($unreadCount)<span class="nav-badge">{{ $unreadCount }}</span>@endif
    </a>
</nav>

@if ($tab === 'people')
    {{-- واتساب بالأساس (docs/plan/41): كل موظّفٍ برقمه، وزرٌّ يفتح محادثته — ومع رقم الشحنة إن جاء منها --}}
    <section class="card overflow-hidden">
        <form method="GET" action="{{ route('staff-chat.index') }}" role="search" class="flex flex-wrap gap-2 border-b border-ink-100 p-4">
            @if ($shipment)<input type="hidden" name="shipment" value="{{ $shipment }}">@endif
            <input name="q" type="search" value="{{ $q }}" class="field-input min-w-56 flex-1 py-1.5" placeholder="ابحث باسم الموظّف أو رقمه">
            <button type="submit" class="btn-ghost px-4">ابحث</button>
            @if ($q !== '')<a href="{{ route('staff-chat.index', array_filter(['shipment' => $shipment])) }}" class="btn-ghost px-4">الكل</a>@endif
        </form>
        @if ($shipment)
            <p class="border-b border-ink-100 bg-primary-50/50 px-4 py-2 text-sm">رسالة واتساب تُفتح وفيها: «بخصوص الشحنة <span class="num">{{ $shipment }}</span>».</p>
        @endif
        <ul class="divide-y divide-ink-100">
            @forelse ($directory as $person)
                @php $whatsapp = \App\Support\Phone::whatsappUrl($person->phone, $shipment !== '' ? "بخصوص الشحنة {$shipment}: " : null); @endphp
                <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <span class="min-w-0">
                        <span class="block font-semibold">{{ $person->name }}</span>
                        <span class="text-xs text-ink-500">
                            {{ $person->rank?->name ?? $person->role->label() }}@if ($person->branch) · {{ $person->branch->name }}@endif
                            · <span class="num" dir="ltr">{{ $person->phone }}</span>
                        </span>
                    </span>
                    <span class="flex flex-wrap gap-2">
                        @if ($whatsapp)
                            <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn-primary py-1.5">
                                <x-icon name="chat" class="size-4"/> واتساب
                            </a>
                        @else
                            <span class="chip chip-mute">لا رقم واتساب صحيح</span>
                        @endif
                        <a href="{{ route('staff-chat.index', array_filter(['to' => 'user:'.$person->id, 'shipment' => $shipment])) }}" class="btn-ghost py-1.5">داخل النظام</a>
                    </span>
                </li>
            @empty
                <li class="px-4 py-10 text-center text-sm text-ink-500">{{ $q !== '' ? 'لا موظّف بهذا الاسم أو الرقم.' : 'لا موظّف آخر بعد.' }}</li>
            @endforelse
        </ul>
    </section>
@else
<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <section class="card h-fit overflow-hidden">
        <div class="flex items-center justify-between border-b border-ink-100 px-4 py-3">
            <h2 class="card-title">المحادثات</h2>
            @if ($thread)
                <a href="{{ route('staff-chat.index') }}" class="btn-ghost px-3 py-1 text-sm">رسالة جديدة</a>
            @endif
        </div>
        <ul class="max-h-[32rem] divide-y divide-ink-100 overflow-y-auto">
            @forelse ($threads as $item)
                @php $isUnread = in_array($item->id, $unread, true); @endphp
                <li>
                    <a href="{{ route('staff-chat.index', ['thread' => $item->id]) }}"
                       @class(['flex items-center justify-between gap-3 px-4 py-3 text-sm hover:bg-primary-50/50', 'bg-primary-50/60' => $thread?->id === $item->id])>
                        <span class="min-w-0">
                            <span class="block truncate {{ $isUnread ? 'font-bold' : 'font-medium' }}">{{ $title($item) }}</span>
                            <span class="text-xs text-ink-500">
                                {{ $item->isTeam() ? ($item->team === $myTeam ? 'قسمك' : 'رسالةٌ لقسم') : 'بينكما' }} ·
                                <span class="num">{{ $item->last_message_at?->format('m-d H:i') }}</span>
                            </span>
                        </span>
                        @if ($isUnread)
                            <span class="chip chip-warn shrink-0">جديد</span>
                        @endif
                    </a>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-sm text-ink-500">لا محادثة بعد. اكتب الأولى من هنا.</li>
            @endforelse
        </ul>
    </section>

    <section class="card flex flex-col p-5 lg:col-span-2">
        @if ($thread)
            <div class="mb-4 border-b border-ink-100 pb-3">
                <h2 class="card-title">{{ $title($thread) }}</h2>
                <p class="card-hint">
                    {{ $thread->isTeam() ? 'محادثة القسم: يراها كل من فيه، ومن كتب إليها.' : 'محادثةٌ بينكما وحدكما.' }}
                </p>
            </div>

            <ol class="mb-4 max-h-[28rem] space-y-3 overflow-y-auto" aria-label="الرسائل">
                @forelse ($messages as $message)
                    @php $mine = $message->user_id === auth()->id(); @endphp
                    <li class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[85%] rounded-2xl px-4 py-2.5 {{ $mine ? 'bg-[var(--brand)] text-white' : 'border border-ink-200 bg-white' }}">
                            @if ($message->shipment)
                                <a href="{{ route('shipments.show', $message->shipment) }}"
                                   class="mb-1 inline-block rounded-md px-2 py-0.5 text-xs {{ $mine ? 'bg-white/15' : 'bg-ink-50' }}">
                                    الشحنة <span class="num">{{ $message->shipment->number }}</span>
                                </a>
                            @endif
                            <p class="whitespace-pre-line text-sm leading-relaxed">{{ \App\Support\ShipmentLinks::text($message->body) }}</p>
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

        <form method="POST" action="{{ route('staff-chat.send') }}" class="mt-auto space-y-3">
            @csrf
            @if ($thread)
                <input type="hidden" name="thread_id" value="{{ $thread->id }}">
            @else
                <div>
                    <label class="field-label" for="to">إلى</label>
                    <select id="to" name="to" class="field-input" data-searchable required>
                        <option value="">اختر موظّفاً أو قسماً</option>
                        <optgroup label="الأقسام — يراها كل من فيه">
                            @foreach ($teams as $key => $name)
                                <option value="{{ $key }}" @selected(old('to', $to) === $key)>قسم {{ $name }}</option>
                            @endforeach
                        </optgroup>
                        <optgroup label="الموظّفون">
                            @foreach ($people as $person)
                                <option value="user:{{ $person->id }}" @selected(old('to', $to) === 'user:'.$person->id)>
                                    {{ $person->name }} — {{ $person->rank?->name ?? $person->role->label() }}
                                </option>
                            @endforeach
                        </optgroup>
                    </select>
                    @error('to') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endif
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                <div class="sm:col-span-3">
                    <label class="field-label" for="body">الرسالة</label>
                    <textarea id="body" name="body" rows="2" maxlength="2000" required class="field-input"
                              placeholder="مثلاً: الراجع 000072 وصل الفرع الرئيسي؟">{{ old('body') }}</textarea>
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
@endif
@endsection
