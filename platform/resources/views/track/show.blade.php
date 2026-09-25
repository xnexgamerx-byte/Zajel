@extends('layouts.public')
@section('title', 'شحنة ' . $shipment->number)

@php
    use App\Enums\ShipmentStatus as S;

    $reason = $events->firstWhere('to_status', S::FailedAttempt->value)?->failureReason?->name_ar;

    // جملةٌ يفهمها الزبون لا اسم حالةٍ داخليّ
    $message = match ($shipment->status) {
        S::Created, S::PendingPickup => 'وصلنا طلب شحنتك، وسنستلمها من المتجر قريباً.',
        S::PickedUp, S::AtHub        => 'شحنتك عندنا، وتُجهَّز للتوصيل.',
        S::InTransit                 => 'شحنتك في الطريق إلى '.$shipment->governorate->name_ar.'.',
        S::OutForDelivery            => 'شحنتك مع المندوب اليوم — سيتّصل بك قبل الوصول.',
        S::Delivered                 => 'سُلّمت شحنتك. شكراً لك.',
        S::PartiallyDelivered        => 'سُلّم جزءٌ من شحنتك.',
        S::FailedAttempt             => 'لم نتمكّن من التسليم'.($reason ? ' ('.$reason.')' : '').'، وسنحاول مرّة أخرى.',
        S::Postponed                 => 'أُجّل التسليم، وسنعاود التواصل معك.',
        S::Returning, S::Returned    => 'أُعيدت الشحنة إلى المتجر.',
        S::Cancelled                 => 'أُلغيت هذه الشحنة.',
        S::Lost, S::Damaged          => 'حدثت مشكلة في شحنتك — تواصل معنا من فضلك.',
    };
@endphp

@section('content')
<section class="card overflow-hidden">
    <div class="border-b border-ink-200 p-5 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-xs text-ink-500">رقم الوصل</div>
                <div class="num text-2xl font-bold text-aeblack-900">{{ $shipment->number }}</div>
            </div>
            <x-status-badge :status="$shipment->status" class="text-sm"/>
        </div>
        <p class="mt-4 font-heading text-lg font-bold text-aeblack-900">{{ $message }}</p>
        <p class="mt-1 text-xs text-ink-500">آخر تحديث {{ $shipment->status_changed_at?->diffForHumans() }}</p>
    </div>

    <dl class="grid grid-cols-2 gap-4 p-5 text-sm sm:p-6">
        <div>
            <dt class="text-ink-500">إلى</dt>
            <dd class="font-medium">{{ \App\Support\Tracking::maskName($shipment->recipient_name) }}</dd>
            <dd class="text-ink-600">{{ $shipment->governorate->name_ar }}@if ($shipment->city) — {{ $shipment->city->name_ar }}@endif</dd>
        </div>
        <div>
            <dt class="text-ink-500">من</dt>
            <dd class="font-medium">{{ $shipment->merchant->business_name }}</dd>
        </div>
        @if ($awaiting)
            <div class="col-span-2">
                <dt class="text-ink-500">المبلغ عند الاستلام</dt>
                <dd class="text-lg font-bold text-aeblack-900">
                    @if ($shipment->cod_amount > 0)
                        <span class="num">{{ number_format($shipment->cod_amount) }}</span> د.ع
                    @else
                        مدفوعة مسبقاً — لا تدفع شيئاً
                    @endif
                </dd>
            </div>
        @endif
    </dl>
</section>

<section class="card mt-5 p-5 sm:p-6">
    <h2 class="card-title mb-4">مسار الشحنة</h2>
    <ol class="space-y-4">
        {{-- شحنةٌ بلا أحداثٍ عامّة (مستوردةٌ من نظامٍ سابق مثلاً): حالتها الآن على الأقلّ --}}
        @if ($events->isEmpty())
            <li class="flex gap-3">
                <span class="mt-1.5 size-2.5 shrink-0 rounded-full bg-primary-600 ring-4 ring-primary-100"></span>
                <div>
                    <div class="font-semibold text-aeblack-900">{{ $shipment->status->label() }}</div>
                    <div class="num text-xs text-ink-500">{{ $shipment->status_changed_at?->format('Y-m-d H:i') }}</div>
                </div>
            </li>
        @endif
        @foreach ($events as $event)
            <li class="flex gap-3">
                <span class="mt-1.5 size-2.5 shrink-0 rounded-full {{ $loop->first ? 'bg-primary-600 ring-4 ring-primary-100' : 'bg-ink-300' }}"></span>
                <div class="min-w-0">
                    <div class="font-semibold {{ $loop->first ? 'text-aeblack-900' : 'text-aeblack-700' }}">{{ $event->toLabel() }}</div>
                    @if ($event->failureReason)
                        <div class="text-sm text-ink-600">{{ $event->failureReason->name_ar }}</div>
                    @endif
                    <div class="num text-xs text-ink-500">{{ $event->created_at->format('Y-m-d H:i') }}</div>
                </div>
            </li>
        @endforeach
    </ol>
</section>

<p class="mt-5 text-center text-sm">
    <a href="{{ route('track') }}" class="font-medium text-primary-600 hover:text-primary-500 hover:underline">تتبّع شحنةً أخرى</a>
</p>
@endsection
