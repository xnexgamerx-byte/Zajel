{{-- المراسلة مغلقة الآن: متى تُفتح، وواتساب الدعم للعاجل --}}
@unless ($open)
    @php $opens = \App\Support\MerchantHours::opensAt(); @endphp
    <div class="alert alert-warn {{ $class ?? '' }}" role="status">
        المراسلة مغلقة الآن — تُستقبل الرسائل {{ \App\Support\MerchantHours::window() }}.
        تُفتح {{ $opens->isToday() ? 'اليوم' : 'غداً' }} الساعة {{ \App\Support\MerchantHours::label($opens->hour) }}.
    </div>
@endunless
