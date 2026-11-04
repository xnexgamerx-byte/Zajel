@props([
    'target',          // الخانة التي يُكتب فيها ما قُرئ: #scan
    'mode' => 'fill',  // fill · submit · lines · press (resources/js/camera-scan.js)
    'press' => null,   // لـpress: الزرّ الذي يُضغط بعد كلّ مسحة
    'status' => null,  // لـpress: رسالة الصفحة عن الممسوح، تُعرض فوق الكاميرا
    'label' => 'امسح بالكاميرا',
])

{{--
  المسح بكاميرا الهاتف (docs/plan/119): مخفيٌّ حتى يتأكّد السكربت أنّ المتصفّح يفتح الكاميرا (HTTPS)،
  فلا يرى أحدٌ زرّاً لا يعمل. والخانة بجانبه تبقى لقارئ الباركود وللكتابة باليد.
--}}
<button type="button" hidden data-camera-scan="{{ $target }}" data-camera-mode="{{ $mode }}"
        @if ($press) data-camera-press="{{ $press }}" @endif @if ($status) data-camera-status="{{ $status }}" @endif
        {{ $attributes->merge(['class' => 'inline-flex items-center justify-center gap-2 rounded-xl bg-brand px-4 py-3 text-base font-bold text-white active:opacity-90']) }}>
    <x-icon name="camera" class="size-5 shrink-0"/>
    <span>{{ $label }}</span>
</button>
