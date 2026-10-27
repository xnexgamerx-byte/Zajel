<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>وصولات الشحن — {{ $company->name }}</title>

    @include('partials.fonts')

    @vite(['resources/css/app.css', 'resources/js/behaviors.js'])

    {{--
      الملصق بالمليمتر لا بالبكسل: يُطبع كما يُقاس. أسود على أبيض فقط — الطابعة
      الحرارية لا ترى لوناً، والرماديّ الفاتح يختفي عليها.
    --}}
    <style>
        @php [, $w, $h] = $sizes[$size]; @endphp
        @page { size: {{ $size === 'a4' ? 'A4' : "{$w}mm {$h}mm" }}; margin: 0; }

        .sheet { display: grid; background: #fff; }
        .sheet-roll { grid-template-columns: {{ $w }}mm; }
        .sheet-a4 { width: 210mm; grid-template-columns: 105mm 105mm; grid-auto-rows: 148.5mm; }

        .label {
            width: {{ $w }}mm; height: {{ $h }}mm; padding: 4mm; overflow: hidden;
            display: flex; flex-direction: column; gap: 2mm;
            color: #000; font-size: 9.5pt; line-height: 1.35; break-inside: avoid;
        }
        .sheet-a4 .label { padding: 5mm; outline: 0.2mm dashed #999; }
        .sheet-roll .label { break-after: page; }
        .sheet-a4 .label:nth-child(4n) { break-after: page; }
        /* الملصق الأصغر (٨×١٢، ١٠×١٠): خطٌّ وباركود ورمزٌ أصغر ليتّسع كلّه */
        .compact .label { padding: 3mm; gap: 1.5mm; font-size: 8pt; line-height: 1.3; }
        .compact .label .barcode { height: 10mm; }
        .compact .label .code { font-size: 10pt; }
        .compact .label .logo { width: 15mm; height: 12mm; font-size: 8pt; }
        .compact .label .qr { width: 16mm; height: 16mm; }
        .compact .label .small { font-size: 6.5pt; }
        .compact .label .long-only { display: none; }

        .label hr { border: 0; border-top: 0.3mm solid #000; margin: 0; }
        .label .barcode { width: 100%; height: 13mm; image-rendering: pixelated; }
        .label .code { font-size: 12pt; line-height: 1.2; }
        /* الشعار بالأبيض والأسود: الطابعة الحرارية لا ترى لوناً */
        .label .logo { width: 20mm; height: 16mm; flex-shrink: 0; display: flex; align-items: center; justify-content: center; text-align: center; font-size: 10pt; line-height: 1.2; overflow: hidden; }
        .label .logo img { max-width: 100%; max-height: 100%; object-fit: contain; filter: grayscale(1) contrast(1.4); }
        .label .qr { width: 22mm; height: 22mm; flex-shrink: 0; }
        .label .amount { border: 0.6mm solid #000; border-radius: 1.5mm; padding: 1.5mm 3mm; }
        .label .tag { border: 0.3mm solid #000; border-radius: 1mm; padding: 0 1.5mm; font-weight: 700; }
        .label .small { font-size: 7.5pt; }

        @media screen {
            .sheet { margin: 6mm auto; width: max-content; gap: 6mm; background: transparent; }
            .sheet-a4 { gap: 0; background: #fff; box-shadow: 0 1mm 4mm rgb(0 0 0 / 0.15); }
            .sheet-roll .label { background: #fff; box-shadow: 0 1mm 4mm rgb(0 0 0 / 0.15); }
        }

        @media print {
            html, body { background: #fff !important; }
            .sheet { margin: 0; gap: 0; }
        }
    </style>
</head>
<body class="bg-ink-100 antialiased print:bg-white">

<div class="sticky top-0 z-10 border-b border-ink-200 bg-white print:hidden">
    <div class="mx-auto flex max-w-3xl flex-wrap items-center gap-3 px-4 py-3">
        <span class="text-sm font-semibold">
            <span class="num">{{ $shipments->count() }}</span>
            {{ $shipments->count() === 1 ? 'وصل' : 'وصولات' }}
        </span>

        {{-- المقاس: ملصقٌ حراريّ (١٠×١٥، ٨×١٢، ١٠×١٠)، أو أربعةٌ في ورقة عادية --}}
        <nav class="tab-nav flex-wrap" aria-label="مقاس الطباعة">
            @foreach ($sizes as $key => [$label])
                <a href="{{ request()->fullUrlWithQuery(['size' => $key]) }}"
                   class="tab-link h-10 {{ $size === $key ? 'tab-link-active' : '' }}">{{ $label }}</a>
            @endforeach
        </nav>

        <button type="button" data-print class="btn-primary ms-auto">اطبع</button>
    </div>
</div>

<main class="sheet {{ $size === 'a4' ? 'sheet-a4' : 'sheet-roll' }} {{ in_array($size, ['80x120', '100x100'], true) ? 'compact' : '' }}">
    @foreach ($shipments as $shipment)
        @php $code = $shipment->barcode ?: $shipment->number; @endphp
        <article class="label">
            {{-- أعلى الملصق (docs/plan/87): شعار الشركة يميناً، والباركود بجانبه — يُقرأ من مسافة، ومن أيّ ماسحة --}}
            <header class="flex items-center gap-2">
                <div class="logo">
                    @if ($company->logoUrl())
                        <img src="{{ $company->logoUrl() }}" alt="{{ $company->name }}">
                    @else
                        <span class="font-heading font-bold">{{ $company->name }}</span>
                    @endif
                </div>
                <div class="min-w-0 flex-1 text-center">
                    <img class="barcode" src="{{ \App\Support\Code128::dataUri($code) }}" alt="">
                    <div class="num code font-bold tracking-widest">{{ $code }}</div>
                </div>
            </header>

            <div class="small flex items-center justify-between gap-2">
                <span class="truncate">
                    <span class="font-bold">{{ $company->name }}</span>
                    @if ($company->phone) · <span class="num">{{ $company->phone }}</span>@endif
                    @if ($code !== $shipment->number) · وصل <span class="num">{{ $shipment->number }}</span>@endif
                </span>
                <span class="num">{{ $shipment->created_at->format('Y-m-d') }}</span>
            </div>

            <hr>

            <section>
                <div class="small">المستلم</div>
                {{-- الاسم اختياريّ: يُطبع إن كُتب، ولا يُطبع «الزبون» مكانه (docs/plan/38) --}}
                @if ($shipment->recipient_name !== \App\Models\Shipment::UNNAMED_RECIPIENT)
                    <div class="text-[12pt] font-bold">{{ $shipment->recipient_name }}</div>
                @endif
                <div class="text-[14pt] font-bold"><span class="num">{{ $shipment->recipient_phone }}</span></div>
                @if ($shipment->recipient_phone_alt)
                    <div class="small">بديل: <span class="num">{{ $shipment->recipient_phone_alt }}</span></div>
                @endif
                <div class="mt-1 font-bold">
                    {{ $shipment->governorate->name_ar }}@if ($shipment->city) — {{ $shipment->city->name_ar }}@endif
                </div>
                @if (filled($shipment->address)) <div>{{ $shipment->address }}</div> @endif
                @if (filled($shipment->landmark)) <div>قرب: {{ $shipment->landmark }}</div> @endif
            </section>

            <div class="amount flex items-center justify-between gap-2">
                <span class="font-bold">المبلغ المطلوب</span>
                @if ($shipment->cod_amount > 0)
                    <span class="text-[16pt] font-bold"><span class="num">{{ number_format($shipment->cod_amount) }}</span> د.ع</span>
                @else
                    <span class="text-[12pt] font-bold">مدفوعة مسبقاً</span>
                @endif
            </div>

            <section class="small">
                <div><span class="font-bold">من:</span> {{ $shipment->merchant->business_name }}
                    @if ($shipment->merchant_reference) · طلب <span class="num">{{ $shipment->merchant_reference }}</span>@endif
                </div>
                <div class="mt-0.5 flex flex-wrap gap-1.5">
                    <span>القطع <span class="num">{{ $shipment->pieces_count }}</span></span>
                    @if ($shipment->weight_grams > 0)
                        <span>· الوزن <span class="num">{{ rtrim(rtrim(number_format($shipment->weight_grams / 1000, 2), '0'), '.') }}</span> كغم</span>
                    @endif
                    @if ($shipment->type === 'exchange') <span class="tag">استبدال</span> @endif
                    @if ($shipment->size && $shipment->size !== 'normal') <span class="tag">حجم {{ \App\Models\Shipment::SIZES[$shipment->size] ?? $shipment->size }}</span> @endif
                    @if ($shipment->is_fragile) <span class="tag">قابل للكسر</span> @endif
                    @if ($shipment->allow_open) <span class="tag">يُسمح بالفتح</span> @endif
                </div>
                @if ($shipment->description)
                    <div class="mt-0.5 line-clamp-1">المحتوى: {{ $shipment->description }}</div>
                @endif
                @if ($shipment->notes)
                    <div class="mt-0.5 line-clamp-2"><span class="font-bold">ملاحظة:</span> {{ $shipment->notes }}</div>
                @endif
            </section>

            {{-- رمز التتبّع أسفل الملصق يساراً --}}
            <footer class="mt-auto flex items-end justify-between gap-2">
                <div class="small">
                    <div class="font-bold">امسح الرمز لتتبّع شحنتك</div>
                    <div class="long-only">أو من صفحة التتبّع برقم الوصل وآخر أربعة أرقام من هاتفك</div>
                </div>
                <img class="qr" src="{{ \App\Support\QrCode::dataUri(\App\Support\Tracking::url($shipment)) }}" alt="">
            </footer>
        </article>
    @endforeach
</main>

</body>
</html>
