@props(['hint' => 'امسح رمز QR من الورقة بقارئ الباركود، أو بكاميرا الهاتف'])

{{--
  «استلم بالرمز» (docs/plan/61): قارئ الباركود يكتب رابط الرمز هنا ويضغط Enter، فتُفتح صفحة
  ما يُستلم به. وكاميرا الهاتف تفتح الرابط نفسه بلا هذا الحقل.
--}}
<form class="card mb-5 flex flex-wrap items-center gap-2 p-3" data-doc-scan role="search">
    <x-icon name="qr" class="size-5 shrink-0 text-ink-500" />
    <label for="doc-scan" class="text-sm font-semibold">استلم بالرمز</label>
    <input id="doc-scan" type="text" autocomplete="off" class="field-input min-w-0 flex-1 py-1.5"
           placeholder="{{ $hint }}" aria-label="رمز الورقة">
</form>
