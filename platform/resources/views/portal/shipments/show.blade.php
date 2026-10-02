@extends('layouts.portal')
@section('title', 'شحنة ' . $shipment->number)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="page-title font-mono" dir="ltr">{{ $shipment->number }}</h1>
            <x-status-badge :status="$shipment->status" />
        </div>
        <p class="mt-1 text-sm text-ink-500">
            أُنشئت {{ $shipment->created_at->format('Y-m-d H:i') }}
            @if ($shipment->merchant_reference)
                · رقمك: <span dir="ltr">{{ $shipment->merchant_reference }}</span>
            @endif
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('portal.shipments.labels', ['ids' => [$shipment->id]]) }}" target="_blank" class="btn-ghost">
            <x-icon name="printer" class="size-5"/>
            طباعة الوصل
        </a>
        <a href="{{ route('portal.shipments.index') }}" class="btn-ghost">رجوع</a>
    </div>
</div>

@if ($shipment->delivery_code && $shipment->status->isOpen())
    {{-- للتاجر وحده: يرسله لزبونه، والمندوب لا يسجّل التسليم بدونه --}}
    <div class="mb-5 flex flex-wrap items-center gap-3 rounded-lg bg-info-50 px-4 py-3 text-sm text-info-700 ring-1 ring-info-200">
        <span class="font-semibold">كود التسليم:</span>
        <span class="num text-lg font-bold tracking-[0.3em]" dir="ltr">{{ $shipment->delivery_code }}</span>
        <span>أرسله لزبونك؛ لا يُسلَّم الطرد إلّا به، ولا يُطبع على الوصل.</span>
    </div>
@endif

@if ($shipment->lastFailureReason && $shipment->status->isOpen())
    <div class="mb-5 rounded-lg bg-warn-50 px-4 py-3 text-sm text-warn-700 ring-1 ring-warn-200">
        <span class="font-semibold">آخر محاولة لم تنجح:</span>
        {{ $shipment->lastFailureReason->name_ar }}
        <span class="text-xs">(مسؤولية: {{ $shipment->lastFailureReason->categoryLabel() }})</span>
        · عدد المحاولات: {{ $shipment->attempts_count }}
    </div>
@endif

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">مسار الشحنة</h2>

            <ol class="relative space-y-5 border-s-2 border-ink-100 ps-5">
                @foreach ($shipment->events as $event)
                    @php $status = \App\Enums\ShipmentStatus::tryFrom($event->to_status); @endphp
                    <li class="relative">
                        <span class="absolute -start-[1.6rem] top-1 grid h-3 w-3 place-items-center rounded-full
                                     {{ $loop->last ? 'bg-[var(--brand)] ring-4 ring-[var(--brand-line)]' : 'bg-ink-300' }}"></span>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold">{{ $event->toLabel() }}</span>
                            <span class="text-xs text-ink-400" dir="ltr">
                                {{ $event->created_at->format('Y-m-d H:i') }}
                            </span>
                        </div>
                        @if ($event->note)
                            <p class="mt-1 text-xs text-ink-600">{{ $event->note }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الزبون والعنوان</h2>
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-ink-500">الاسم</dt>
                     <dd class="font-medium">{{ $shipment->recipient_name }}</dd></div>
                <div><dt class="text-ink-500">الهاتف</dt>
                     <dd class="font-medium" dir="ltr">{{ $shipment->recipient_phone }}</dd></div>
                <div><dt class="text-ink-500">المحافظة / المنطقة</dt>
                     <dd class="font-medium">{{ $shipment->governorate->name_ar }}
                         @if ($shipment->city) — {{ $shipment->city->name_ar }} @endif</dd></div>
                <div><dt class="text-ink-500">القطع</dt>
                     <dd class="font-medium" dir="ltr">{{ $shipment->pieces_count }}</dd></div>
                @if (filled($shipment->address))
                    <div class="sm:col-span-2"><dt class="text-ink-500">العنوان</dt>
                         <dd class="font-medium">{{ $shipment->address }}</dd></div>
                @endif
                <div class="sm:col-span-2"><dt class="text-ink-500">نقطة دالّة</dt>
                     <dd class="font-medium text-[var(--brand)]">{{ $shipment->landmark ?: '—' }}</dd></div>
                @if ($shipment->description)
                    <div class="sm:col-span-2"><dt class="text-ink-500">المحتوى</dt>
                         <dd class="font-medium">{{ $shipment->description }}</dd></div>
                @endif
            </dl>
        </section>
    </div>

    <div>
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">حساب الشحنة</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-ink-600">المطلوب من الزبون</dt>
                    <dd class="font-semibold" dir="ltr">{{ number_format($shipment->cod_amount) }}</dd>
                </div>
                @if ($shipment->collected_amount)
                    <div class="flex justify-between">
                        <dt class="text-ink-600">المحصَّل فعلاً</dt>
                        <dd class="font-semibold text-ok-700" dir="ltr">
                            {{ number_format($shipment->collected_amount) }}
                        </dd>
                    </div>
                @endif
                <div class="flex justify-between border-t border-ink-100 pt-2">
                    <dt class="text-ink-600">أجرة التوصيل</dt>
                    <dd dir="ltr">{{ number_format($shipment->delivery_fee) }}</dd>
                </div>
                @if ($shipment->cod_fee)
                    <div class="flex justify-between">
                        <dt class="text-ink-600">عمولة التحصيل</dt>
                        <dd dir="ltr">{{ number_format($shipment->cod_fee) }}</dd>
                    </div>
                @endif
                @if ($shipment->status === \App\Enums\ShipmentStatus::Returned && $shipment->return_fee)
                    <div class="flex justify-between text-warn-700">
                        <dt>أجرة الراجع</dt>
                        <dd dir="ltr">{{ number_format($shipment->return_fee) }}</dd>
                    </div>
                @elseif ($shipment->wasDelivered() && in_array($shipment->status, [\App\Enums\ShipmentStatus::Returning, \App\Enums\ShipmentStatus::Returned], true))
                    {{-- باقي الواصل الجزئي يرجع إليك بلا أجرة (الوثيقة ٢٤) --}}
                    <div class="flex justify-between text-ink-600">
                        <dt>باقيها راجعٌ إليك بلا أجرة</dt>
                        <dd dir="ltr">0</dd>
                    </div>
                @endif
                <div class="flex justify-between border-t-2 border-ink-300 pt-2">
                    <dt class="font-bold">{{ $shipment->merchant_due >= 0 ? 'لك' : 'عليك' }}</dt>
                    <dd class="text-lg font-bold {{ $shipment->merchant_due >= 0 ? 'text-[var(--brand)]' : 'text-bad-700' }}"
                        dir="ltr">{{ number_format(abs($shipment->merchant_due)) }} د.ع</dd>
                </div>
            </dl>

            @if (! $shipment->status->isOpen())
                <p class="mt-3 border-t border-ink-100 pt-3 text-xs text-ink-500">
                    {{ $shipment->merchant_settled_at
                        ? 'دخلت كشف حساب بتاريخ ' . $shipment->merchant_settled_at->format('Y-m-d')
                        : 'لم تدخل كشف حساب بعد.' }}
                </p>
            @endif
        </section>

        {{-- «محادثة الشحنة»: سؤالك عنها وصورها في مكانٍ واحد --}}
        <section class="card p-5">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-sm font-bold">محادثة عن الشحنة</h2>
                <a href="{{ route('portal.support.index', ['shipment_number' => $shipment->number]) }}#new-conversation"
                   class="text-xs font-semibold text-[var(--brand)] hover:underline">اسأل عنها</a>
            </div>
            @forelse ($conversations as $conversation)
                <a href="{{ route('portal.support.show', $conversation) }}" class="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2 text-sm hover:bg-ink-50">
                    <span class="min-w-0 truncate {{ $conversation->merchant_unread ? 'font-bold' : 'font-medium' }}">{{ $conversation->subject }}</span>
                    <span class="flex shrink-0 items-center gap-2">
                        @if ($conversation->merchant_unread)
                            <span class="chip chip-info">ردٌّ جديد</span>
                        @elseif (! $conversation->isOpen())
                            <span class="chip chip-mute">مغلقة</span>
                        @endif
                        <span class="num text-xs text-ink-500">{{ $conversation->last_message_at?->format('m-d H:i') }}</span>
                    </span>
                </a>
            @empty
                <p class="text-sm text-ink-500">سؤالٌ عنها مع صورةٍ يصل الشركة هنا ويبقى معها.</p>
            @endforelse
        </section>
    </div>
</div>
@endsection
