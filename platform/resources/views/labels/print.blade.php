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

      وكلّ ما فيه بوحدةٍ واحدة (--u، docs/plan/94): مليمترٌ في ملصق ١٠×١٥، وأكبر منه في ورق A5 وA4 وA3
      وأصغر في الملصق المربّع — فيمتلئ كلّ مقاسٍ بالتوازن نفسه.
    --}}
    <style>
        @php
            [, $w, $h] = $sizes[$size];
            $paper = \App\Http\Controllers\ShipmentLabelController::PAPER[$size] ?? null;
            $u = round(min($w / 100, $h / 128), 3);
            // الورقة الطويلة: نصّ الطلب أكبر بقدر طولها، فتمتلئ (١ للمربّع، و١٫١٧ لـ١٠×١٥)
            $f = round(max(1, min(1.2, ($h / $w) / 1.28)), 3);
        @endphp
        @page { size: {{ $size === 'a4' ? 'A4' : ($paper ?? "{$w}mm {$h}mm") }}; margin: 0; }

        .sheet { display: grid; background: #fff; }
        .sheet-roll { grid-template-columns: {{ $w }}mm; }
        .sheet-a4 { width: 210mm; grid-template-columns: 105mm 105mm; grid-auto-rows: 148.5mm; }

        .label {
            --u: {{ $u }}mm; --f: {{ $f }};
            width: {{ $w }}mm; height: {{ $h }}mm; padding: calc(var(--u) * 4.5); overflow: hidden;
            display: flex; flex-direction: column; justify-content: space-between; gap: calc(var(--u) * 2.2);
            color: #000; font-size: calc(var(--u) * 3.4); line-height: 1.35; break-inside: avoid;
        }
        .sheet-a4 .label { outline: 0.2mm dashed #999; }
        .sheet-roll .label { break-after: page; }
        .sheet-a4 .label:nth-child(4n) { break-after: page; }

        .label hr { border: 0; border-top: calc(var(--u) * 0.35) solid #000; margin: 0; }

        /* الرأس: الشعار يميناً ورمز التتبّع يساراً، وحدهما */
        .label .head { display: flex; align-items: center; justify-content: space-between; gap: calc(var(--u) * 2.5); }
        .label .mark { width: calc(var(--u) * 22); height: calc(var(--u) * 22); display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .label .mark img { max-width: 100%; max-height: 100%; object-fit: contain; filter: grayscale(1) contrast(1.4); }
        /* حرف الشعار بخلفيةٍ شفّافة: أسود كاملاً، أوضح على الطابعة الحرارية */
        .label .mark img.solid { filter: grayscale(1) brightness(0); }
        .label .mark .initial { font-size: calc(var(--u) * 17); line-height: 1; font-weight: 800; }
        .label .qr { width: calc(var(--u) * 27); height: calc(var(--u) * 27); image-rendering: pixelated; }

        .label .muted { font-size: calc(var(--u) * 2.8); }
        .label .recipient-name { font-size: calc(var(--u) * 4.8 * var(--f)); font-weight: 800; }
        .label .recipient-phone { font-size: calc(var(--u) * 7.4 * var(--f)); font-weight: 800; line-height: 1.15; }
        .label .place { font-size: calc(var(--u) * 5.2 * var(--f)); font-weight: 800; }
        .label .address { font-size: calc(var(--u) * 4 * var(--f)); }
        .label .details { font-size: calc(var(--u) * 3.4 * var(--f)); }
        .label .details .row + .row { margin-top: calc(var(--u) * 0.8 * var(--f)); }
        .label .tag { border: calc(var(--u) * 0.3) solid #000; border-radius: calc(var(--u) * 1); padding: 0 calc(var(--u) * 1.5); font-weight: 700; }

        /* أسفل الوصل: المبلغ، ثم الباركود في الوسط */
        .label .amount { border: calc(var(--u) * 0.7) solid #000; border-radius: calc(var(--u) * 2); padding: calc(var(--u) * 2) calc(var(--u) * 3.5); }
        .label .amount .what { font-size: calc(var(--u) * 4.2); font-weight: 800; }
        .label .amount .sum { font-size: calc(var(--u) * 8); font-weight: 800; line-height: 1.1; }
        .label .barcode { display: block; margin-inline: auto; width: calc(var(--u) * 72); height: calc(var(--u) * 14); image-rendering: pixelated; }
        .label .code { font-size: calc(var(--u) * 4.4); font-weight: 800; letter-spacing: 0.25em; line-height: 1.15; }

        .label .bottom { display: flex; flex-direction: column; gap: calc(var(--u) * 2.2); }
        .label .clamp-3 { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; overflow: hidden; }

        /* الملصق الأصغر (٨×١٢): ما لا يتّسع له يُترك */
        .compact .label .long-only { display: none; }

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

        {{-- المقاس: ملصقٌ حراريّ، أو ورقٌ بوصلٍ واحد، أو أربعةٌ في ورقة A4 --}}
        <nav class="tab-nav flex-wrap" aria-label="مقاس الطباعة">
            @foreach ($sizes as $key => [$label])
                <a href="{{ request()->fullUrlWithQuery(['size' => $key]) }}"
                   class="tab-link h-10 {{ $size === $key ? 'tab-link-active' : '' }}">{{ $label }}</a>
            @endforeach
        </nav>

        <button type="button" data-print class="btn-primary ms-auto">اطبع</button>
    </div>
</div>

<main class="sheet {{ $size === 'a4' ? 'sheet-a4' : 'sheet-roll' }} {{ $size === '80x120' ? 'compact' : '' }}">
    @foreach ($shipments as $shipment)
        @php $code = $shipment->barcode ?: $shipment->number; @endphp
        <article class="label">
            {{-- أعلى الوصل (docs/plan/94): شعار الشركة يميناً، ورمز التتبّع يساراً --}}
            <header class="head">
                <div class="mark">
                    @if ($company->markUrl())
                        <img src="{{ $company->markUrl() }}" alt="{{ $company->name }}" @class(['solid' => filled($company->mark_path)])>
                    @else
                        <span class="initial font-heading">{{ $company->initial() }}</span>
                    @endif
                </div>
                <img class="qr" src="{{ \App\Support\QrCode::dataUri(\App\Support\Tracking::url($shipment)) }}" alt="رمز التتبّع">
            </header>

            <hr>

            <section>
                <div class="muted">المستلم</div>
                {{-- الاسم اختياريّ: يُطبع إن كُتب، ولا يُطبع «الزبون» مكانه (docs/plan/38) --}}
                @if ($shipment->recipient_name !== \App\Models\Shipment::UNNAMED_RECIPIENT)
                    <div class="recipient-name">{{ $shipment->recipient_name }}</div>
                @endif
                <div class="recipient-phone"><span class="num">{{ $shipment->recipient_phone }}</span></div>
                @if ($shipment->recipient_phone_alt)
                    <div class="address">بديل: <span class="num">{{ $shipment->recipient_phone_alt }}</span></div>
                @endif
                <div class="place">
                    {{ $shipment->governorate->name_ar }}@if ($shipment->city) — {{ $shipment->city->name_ar }}@endif
                </div>
                @if (filled($shipment->address)) <div class="address">{{ $shipment->address }}</div> @endif
                @if (filled($shipment->landmark)) <div class="address">قرب: {{ $shipment->landmark }}</div> @endif
            </section>

            <hr>

            <section class="details">
                <div class="row"><span class="font-bold">من:</span> {{ $shipment->merchant->business_name }}
                    @if ($shipment->merchant_reference) · طلب <span class="num">{{ $shipment->merchant_reference }}</span>@endif
                </div>
                <div class="row flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span><span class="font-bold">القطع:</span> <span class="num">{{ $shipment->pieces_count }}</span></span>
                    @if ($shipment->weight_grams > 0)
                        <span><span class="font-bold">الوزن:</span> <span class="num">{{ rtrim(rtrim(number_format($shipment->weight_grams / 1000, 2), '0'), '.') }}</span> كغم</span>
                    @endif
                    @if ($shipment->type === 'exchange') <span class="tag">استبدال</span> @endif
                    @if ($shipment->size && $shipment->size !== 'normal') <span class="tag">حجم {{ \App\Models\Shipment::SIZES[$shipment->size] ?? $shipment->size }}</span> @endif
                    @if ($shipment->is_fragile) <span class="tag">قابل للكسر</span> @endif
                    @if ($shipment->allow_open) <span class="tag">يُسمح بالفتح</span> @endif
                </div>
                @if ($shipment->description)
                    <div class="row line-clamp-2"><span class="font-bold">المحتوى:</span> {{ $shipment->description }}</div>
                @endif
                @if ($shipment->notes)
                    <div class="row clamp-3"><span class="font-bold">ملاحظة:</span> {{ $shipment->notes }}</div>
                @endif
                @if ($code !== $shipment->number)
                    <div class="row muted">وصل <span class="num">{{ $shipment->number }}</span></div>
                @endif
            </section>

            {{-- أسفل الوصل (docs/plan/94): المبلغ، وتحته الباركود في الوسط --}}
            <footer class="bottom">
                <div class="amount flex items-center justify-between gap-2">
                    <span class="what">المبلغ المطلوب</span>
                    @if ($shipment->cod_amount > 0)
                        <span class="sum"><span class="num">{{ number_format($shipment->cod_amount) }}</span> د.ع</span>
                    @else
                        <span class="sum">مدفوعة مسبقاً</span>
                    @endif
                </div>
                <div class="text-center">
                    <img class="barcode" src="{{ \App\Support\Code128::dataUri($code) }}" alt="">
                    <div class="num code">{{ $code }}</div>
                </div>
            </footer>
        </article>
    @endforeach
</main>

</body>
</html>
