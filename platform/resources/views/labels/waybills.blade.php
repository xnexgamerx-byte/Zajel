<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>وصولات للطباعة — {{ $company->name }}</title>

    @include('partials.fonts')

    @vite(['resources/css/app.css', 'resources/js/behaviors.js'])

    {{--
      وصلٌ مطبوع مسبقاً يكتب عليه التاجر بيده: بالمليمتر لا بالبكسل، أسود على أبيض —
      الطابعة الحرارية لا ترى لوناً. ملصقٌ في كل صفحة بمقاس الطابعة المختار.
      الباركود ورمز QR يحملان الرقم نفسه: تقرؤه كل ماسحة، ويُدخَل به الوصل ويُفتح.
    --}}
    @php [$w, $h] = \App\Models\WaybillBook::PRINT_SIZES[$size]; $square = $size === '100x100'; @endphp
    <style>
        @page { size: {{ $w }}mm {{ $h }}mm; margin: 0; }

        .waybill {
            width: {{ $w }}mm; height: {{ $h }}mm; padding: 3mm; overflow: hidden; break-after: page;
            display: flex; flex-direction: column; gap: 1.6mm;
            color: #000; background: #fff; font-size: 8pt; line-height: 1.3;
        }
        .wb-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 2mm; }
        .wb-company { font-weight: 800; font-size: 11pt; line-height: 1.15; }
        .wb-small { font-size: 6.8pt; }
        .wb-no { font-weight: 800; font-size: 14pt; letter-spacing: 0.3mm; white-space: nowrap; }
        .wb-date { white-space: nowrap; }
        .wb-date i { display: inline-block; width: 6mm; border-bottom: 0.25mm solid #000; }
        .wb-barcode { width: 100%; height: {{ $square ? 11 : 12 }}mm; image-rendering: pixelated; display: block; }
        .wb-box { border: 0.35mm solid #000; border-radius: 1.5mm; padding: 1.2mm 2mm 1.6mm; }
        .wb-legend { font-weight: 800; font-size: 7.5pt; margin-bottom: 0.4mm; }
        .wb-field { display: flex; align-items: flex-end; gap: 1.5mm; min-height: {{ $square ? 6.2 : 6.6 }}mm; white-space: nowrap; }
        .wb-field > span { font-size: 7.5pt; }
        .wb-field > i { flex: 1; border-bottom: 0.25mm dotted #000; height: 4mm; }
        .wb-field > b { flex: 1; font-size: 8.5pt; overflow: hidden; text-overflow: ellipsis; }
        .wb-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 2.5mm; }
        .wb-amount > span:last-child { font-weight: 700; }
        .wb-qr { width: {{ $square ? 22 : 17 }}mm; height: {{ $square ? 22 : 17 }}mm; flex-shrink: 0; }
        .wb-terms { margin: 0; padding: 0; list-style: none; font-size: 6.2pt; line-height: 1.3; }
        .wb-terms li::before { content: '• '; }
        .wb-foot { display: flex; align-items: flex-end; gap: 2mm; margin-top: auto; }
        .wb-body { display: grid; grid-template-columns: 1fr 30mm; gap: 2.5mm; align-items: start; }
        .wb-side { display: flex; flex-direction: column; align-items: center; gap: 1.5mm; }

        @media screen {
            body { padding: 6mm 0; }
            .sheet { display: grid; justify-content: center; gap: 6mm; }
            .waybill { box-shadow: 0 1mm 4mm rgb(0 0 0 / 0.15); }
        }

        @media print {
            html, body { background: #fff !important; }
            .sheet { display: block; }
        }
    </style>
</head>
<body class="bg-ink-100 antialiased print:bg-white">

<div class="sticky top-0 z-10 border-b border-ink-200 bg-white print:hidden">
    <div class="mx-auto flex max-w-3xl flex-wrap items-center gap-3 px-4 py-3">
        @if ($back)<a href="{{ $back }}" class="btn-ghost h-10">رجوع</a>@endif
        <span class="text-sm font-semibold">
            {{ \App\Support\Arabic::waybills(count($codes)) }}
            <span class="num font-normal text-ink-500">{{ $book->firstCode() }}–{{ $book->lastCode() }}</span>
            @if ($usedCount)
                <span class="block text-xs font-normal text-ink-500">استُعمل من الدفتر {{ \App\Support\Arabic::waybills($usedCount) }} فلا تُطبع ثانيةً.</span>
            @endif
        </span>

        {{-- مقاس ملصقات طابعة التاجر --}}
        <nav class="tab-nav" aria-label="مقاس الطباعة">
            @foreach (\App\Models\WaybillBook::PRINT_SIZES as $key => [$sw, $sh])
                <a href="{{ request()->fullUrlWithQuery(['size' => $key]) }}"
                   class="tab-link h-10 {{ $size === $key ? 'tab-link-active' : '' }}"><span class="num">{{ $sw }}×{{ $sh }}</span> ملم</a>
            @endforeach
        </nav>

        {{-- ما يُضبط في نافذة الطباعة مرّةً على طابعة الملصقات --}}
        <span class="text-xs text-ink-500">في نافذة الطباعة: الورق <span class="num">{{ $w }}×{{ $h }}</span> ملم، والهوامش «بلا»، والمقياس <span class="num">100%</span>.</span>

        <button type="button" data-print class="btn-primary ms-auto" @disabled($codes === [])>اطبع</button>
    </div>
</div>

<main class="sheet">
    @if ($codes === [])
        <p class="mx-auto max-w-md px-4 py-16 text-center text-ink-500 print:hidden">استُعملت وصولات هذا الدفتر كلّها؛ اطبع دفتراً جديداً.</p>
    @endif
    @foreach ($codes as $code)
        <article class="waybill">
            <header class="wb-head">
                <div class="min-w-0">
                    <div class="wb-company">{{ $company->name }}</div>
                    @if ($company->phone)
                        <div class="wb-small"><span class="num">{{ $company->phone }}</span></div>
                    @endif
                </div>
                <div class="text-end">
                    <div class="wb-no">№ <span class="num">{{ $code }}</span></div>
                    {{-- التاريخ يُكتب باليد يوم الإرسال --}}
                    <div class="wb-small wb-date">التاريخ: <span dir="ltr"><i></i> / <i></i> / 20<i></i></span></div>
                </div>
            </header>

            <img class="wb-barcode" src="{{ \App\Support\Code128::dataUri($code) }}" alt="">

            @if ($square)
                <div class="wb-body">
                    <section class="wb-box">@include('labels._waybill_fields')</section>
                    <div class="wb-side">
                        <img class="wb-qr" src="{{ \App\Support\QrCode::dataUri($code) }}" alt="">
                        <ul class="wb-terms">
                            @foreach ($terms as $term)<li>{{ $term }}</li>@endforeach
                        </ul>
                    </div>
                </div>
                @include('labels._waybill_notes', ['lines' => 2])
            @else
                <section class="wb-box">@include('labels._waybill_fields')</section>
                @include('labels._waybill_notes', ['lines' => 3])
                <footer class="wb-foot">
                    <img class="wb-qr" src="{{ \App\Support\QrCode::dataUri($code) }}" alt="">
                    <ul class="wb-terms">
                        @foreach ($terms as $term)<li>{{ $term }}</li>@endforeach
                    </ul>
                </footer>
            @endif
        </article>
    @endforeach
</main>

</body>
</html>
