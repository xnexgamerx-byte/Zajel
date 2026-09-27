@extends('layouts.portal')
@section('title', 'الدعم')

@section('content')
<div class="mb-5">
    <h1 class="page-title">الدعم</h1>
    <p class="mt-1 text-sm text-ink-500">اسأل {{ $company->name }} هنا، وتجد الردّ في المحادثة نفسها.</p>
</div>

{{-- واتساب محافظة التاجر إن كان لها رقم، وإلّا رقم الشركة --}}
@php $support = $company->supportWhatsappFor(auth()->user()->merchant?->governorate_id); @endphp
@if ($link = \App\Support\Phone::whatsappUrl($support, 'مرحباً، أنا '.auth()->user()->merchant?->business_name))
    <a href="{{ $link }}" target="_blank" rel="noopener"
       class="card mb-5 flex items-center justify-between gap-3 p-4 transition hover:border-ok-200">
        <div>
            <p class="font-semibold">أو راسلنا على واتساب</p>
            <p class="num text-sm text-ink-500">{{ $support }}
                @if ($company->setting('support.hours')) · <span class="font-sans">{{ $company->setting('support.hours') }}</span>@endif
            </p>
        </div>
        <span class="chip chip-ok">واتساب ←</span>
    </a>
@endif

@if ($complaints = $company->setting('support.complaints'))
    <p class="mb-5 text-sm text-ink-600">للشكاوى: <a href="tel:{{ $complaints }}" class="num font-semibold text-[var(--brand)] hover:underline">{{ $complaints }}</a></p>
@endif

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <section class="card overflow-hidden lg:col-span-2">
        @if ($conversations->isEmpty())
            <p class="p-10 text-center text-ink-500">لم تبدأ محادثة بعد.</p>
        @else
            <ul class="divide-y divide-ink-100">
                @foreach ($conversations as $conversation)
                    <li>
                        <a href="{{ route('portal.support.show', $conversation) }}" class="flex items-start justify-between gap-3 px-5 py-3.5 transition hover:bg-ink-50">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    @if ($conversation->merchant_unread)
                                        <span class="h-2 w-2 shrink-0 rounded-full bg-[var(--brand)]" aria-label="ردٌّ جديد"></span>
                                    @endif
                                    <span class="truncate font-semibold">{{ $conversation->subject }}</span>
                                </div>
                                @if ($conversation->shipment)
                                    <p class="mt-0.5 text-sm text-ink-500">وصل <span class="num">{{ $conversation->shipment->number }}</span></p>
                                @endif
                            </div>
                            <div class="shrink-0 text-end">
                                @if ($conversation->merchant_unread)
                                    <span class="chip chip-info">ردٌّ جديد</span>
                                @elseif ($conversation->awaitsUs())
                                    <span class="chip chip-mute">بانتظار الردّ</span>
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

    <form method="POST" action="{{ route('portal.support.store') }}" enctype="multipart/form-data" class="card h-fit space-y-4 p-5" id="new-conversation">
        @csrf
        <h2 class="card-title">سؤالٌ جديد</h2>
        <div>
            <label class="field-label" for="subject">الموضوع</label>
            <input id="subject" name="subject" class="field-input" maxlength="160" required value="{{ old('subject') }}"
                   placeholder="مثال: شحنة لم تصل منذ أسبوع">
        </div>
        <div>
            <label class="field-label" for="shipment_number">رقم الوصل (إن كان عن شحنة)</label>
            <input id="shipment_number" name="shipment_number" class="field-input num" value="{{ old('shipment_number', request('shipment_number')) }}">
        </div>
        <div>
            <label class="field-label" for="body">سؤالك</label>
            <textarea id="body" name="body" rows="4" class="field-input" maxlength="2000">{{ old('body') }}</textarea>
        </div>
        <div>
            <label class="field-label" for="attachment">ملف (اختياري)</label>
            <input id="attachment" name="attachment" type="file" class="field-input" accept="image/jpeg,image/png,image/webp,application/pdf">
            <p class="mt-1 text-xs text-ink-500">صورة أو PDF حتى 5 MB.</p>
        </div>
        <button type="submit" class="btn-primary w-full">أرسل</button>
    </form>
</div>
@endsection
