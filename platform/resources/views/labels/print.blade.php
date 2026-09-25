<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>وصولات الشحن — {{ $company->name }}</title>

    @include('partials.fonts')

    @vite(['resources/css/app.css'])

    {{--
      الملصق بالمليمتر لا بالبكسل: يُطبع كما يُقاس. أسود على أبيض فقط — الطابعة
      الحرارية لا ترى لوناً، والرماديّ الفاتح يختفي عليها.
    --}}
    <style>
        @page { size: {{ $size === 'a4' ? 'A4' : '100mm 150mm' }}; margin: 0; }

        .sheet { display: grid; background: #fff; }
        .sheet-a6 { grid-template-columns: 100mm; }
        .sheet-a4 { width: 210mm; grid-template-columns: 105mm 105mm; grid-auto-rows: 148.5mm; }

        .label {
            width: 100mm; height: 150mm; padding: 4mm; overflow: hidden;
            display: flex; flex-direction: column; gap: 2mm;
            color: #000; font-size: 9.5pt; line-height: 1.35; break-inside: avoid;
        }
        .sheet-a4 .label { width: 105mm; height: 148.5mm; padding: 5mm; outline: 0.2mm dashed #999; }
        .sheet-a6 .label { break-after: page; }
        .sheet-a4 .label:nth-child(4n) { break-after: page; }

        .label hr { border: 0; border-top: 0.3mm solid #000; margin: 0; }
        .label .barcode { width: 100%; height: 15mm; image-rendering: pixelated; }
        .label .qr { width: 22mm; height: 22mm; flex-shrink: 0; }
        .label .amount { border: 0.6mm solid #000; border-radius: 1.5mm; padding: 1.5mm 3mm; }
        .label .tag { border: 0.3mm solid #000; border-radius: 1mm; padding: 0 1.5mm; font-weight: 700; }
        .label .small { font-size: 7.5pt; }

        @media screen {
            .sheet { margin: 6mm auto; width: max-content; gap: 6mm; background: transparent; }
            .sheet-a4 { gap: 0; background: #fff; box-shadow: 0 1mm 4mm rgb(0 0 0 / 0.15); }
            .sheet-a6 .label { background: #fff; box-shadow: 0 1mm 4mm rgb(0 0 0 / 0.15); }
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

        {{-- المقاس: ملصقٌ حراريّ ١٠×١٥، أو أربعةٌ في ورقة عادية --}}
        <nav class="tab-nav" aria-label="مقاس الطباعة">
            <a href="{{ request()->fullUrlWithQuery(['size' => 'a6']) }}"
               class="tab-link h-10 {{ $size === 'a6' ? 'tab-link-active' : '' }}">ملصق ١٠×١٥</a>
            <a href="{{ request()->fullUrlWithQuery(['size' => 'a4']) }}"
               class="tab-link h-10 {{ $size === 'a4' ? 'tab-link-active' : '' }}">A4 — أربعة في الورقة</a>
        </nav>

        <button type="button" onclick="window.print()" class="btn-primary ms-auto">اطبع</button>
    </div>
</div>

<main class="sheet sheet-{{ $size }}">
    @foreach ($shipments as $shipment)
        @php $code = $shipment->barcode ?: $shipment->number; @endphp
        <article class="label">
            <header class="flex items-center justify-between gap-2">
                <div class="min-w-0">
                    <div class="truncate font-heading text-[12pt] font-bold">{{ $company->name }}</div>
                    @if ($company->phone)
                        <div class="small"><span class="num">{{ $company->phone }}</span></div>
                    @endif
                </div>
                <div class="num small text-end">{{ $shipment->created_at->format('Y-m-d') }}</div>
            </header>

            <hr>

            {{-- الباركود بعرض الملصق: يُقرأ من مسافة، ومن أيّ ماسحة --}}
            <div class="text-center">
                <img class="barcode" src="{{ \App\Support\Code128::dataUri($code) }}" alt="">
                <div class="num text-[15pt] font-bold tracking-widest">{{ $code }}</div>
                @if ($code !== $shipment->number)
                    <div class="small">وصل <span class="num">{{ $shipment->number }}</span></div>
                @endif
            </div>

            <hr>

            <section>
                <div class="small">المستلم</div>
                <div class="text-[12pt] font-bold">{{ $shipment->recipient_name }}</div>
                <div class="text-[14pt] font-bold"><span class="num">{{ $shipment->recipient_phone }}</span></div>
                @if ($shipment->recipient_phone_alt)
                    <div class="small">بديل: <span class="num">{{ $shipment->recipient_phone_alt }}</span></div>
                @endif
                <div class="mt-1 font-bold">
                    {{ $shipment->governorate->name_ar }}@if ($shipment->city) — {{ $shipment->city->name_ar }}@endif
                </div>
                <div>{{ $shipment->address }}</div>
                <div>قرب: {{ $shipment->landmark }}</div>
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

            <footer class="mt-auto flex items-end gap-2">
                <img class="qr" src="{{ \App\Support\QrCode::dataUri(\App\Support\Tracking::url($shipment)) }}" alt="">
                <div class="small">
                    <div class="font-bold">امسح الرمز لتتبّع شحنتك</div>
                    <div>أو من صفحة التتبّع برقم الوصل وآخر أربعة أرقام من هاتفك</div>
                </div>
            </footer>
        </article>
    @endforeach
</main>

</body>
</html>
