@props(['rows', 'merchant', 'action', 'back', 'path', 'merchantId' => null])

@php
    $bad = $rows->filter(fn ($row) => ! empty($row['errors']));
    $good = $rows->reject(fn ($row) => ! empty($row['errors']));

    // استعلام واحد لكل المحافظات بدل استعلام في كل صفّ
    $governorates = \App\Models\Governorate::pluck('name_ar', 'id');
@endphp

<div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
    <div class="stat">
        <div class="stat-label">صفوف الملف</div>
        <div class="stat-value"><span class="num">{{ number_format($rows->count()) }}</span></div>
    </div>
    <div class="stat">
        <div class="stat-label">جاهزة للاستيراد</div>
        <div class="mt-1 text-2xl font-bold text-ok-700"><span class="num">{{ number_format($good->count()) }}</span></div>
    </div>
    <div class="stat">
        <div class="stat-label">فيها أخطاء</div>
        <div class="mt-1 text-2xl font-bold {{ $bad->isEmpty() ? 'text-ink-400' : 'text-bad-700' }}">
            <span class="num">{{ number_format($bad->count()) }}</span>
        </div>
    </div>
    <div class="stat">
        <div class="stat-label">مجموع المبالغ</div>
        <div class="mt-1 text-2xl font-bold">
            <span class="num">{{ number_format($good->sum(fn ($r) => $r['data']['cod_amount'])) }}</span>
            <span class="text-sm font-medium text-ink-500">د.ع</span>
        </div>
    </div>
</div>

@if ($bad->isNotEmpty())
    <section class="card mb-5 border-bad-200 p-5">
        <h2 class="card-title text-bad-700">صفوف تحتاج تصحيحاً</h2>
        <p class="card-hint mb-4">رقم الصفّ كما في ملف Excel، فالتصحيح مباشر.</p>

        <div class="max-h-80 overflow-y-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>الصفّ</th>
                        <th>المستلم</th>
                        <th>الخطأ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bad as $row)
                        <tr>
                            <td class="num font-semibold">{{ $row['row'] }}</td>
                            <td>{{ $row['data']['recipient_name'] ?: '—' }}</td>
                            <td class="text-bad-700">{{ implode(' · ', $row['errors']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

@if ($good->isNotEmpty())
    <section class="card mb-5 overflow-hidden">
        <h2 class="card-title border-b border-ink-100 px-5 py-4">
            {{ $good->count() > 20 ? 'معاينة أول 20 صفّاً من '.number_format($good->count()) : 'الصفوف الجاهزة للاستيراد' }}
        </h2>

        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>الصفّ</th>
                        <th>المستلم</th>
                        <th>الهاتف</th>
                        <th>الوجهة</th>
                        <th>النقطة الدالّة</th>
                        <th>المبلغ</th>
                        <th>رقم الطلب</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($good->take(20) as $row)
                        <tr>
                            <td class="num text-ink-500">{{ $row['row'] }}</td>
                            <td class="font-medium">{{ $row['data']['recipient_name'] }}</td>
                            <td class="num text-ink-600">{{ $row['data']['recipient_phone'] }}</td>
                            <td class="text-ink-600">
                                {{ $governorates[$row['data']['governorate_id']] ?? '—' }}
                            </td>
                            <td class="max-w-56 truncate text-ink-600">{{ $row['data']['landmark'] }}</td>
                            <td class="num font-semibold">{{ number_format($row['data']['cod_amount']) }}</td>
                            <td class="num text-ink-500">{{ $row['data']['merchant_reference'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<div class="card flex flex-wrap items-center gap-3 p-5">
    <form method="POST" action="{{ $action }}" class="flex flex-wrap items-center gap-3">
        @csrf
        <input type="hidden" name="path" value="{{ $path }}">
        @if ($merchantId)
            <input type="hidden" name="merchant_id" value="{{ $merchantId }}">
        @endif

        @if ($bad->isEmpty())
            <button type="submit" class="btn-primary">
                استورد الشحنات ({{ number_format($good->count()) }})
            </button>
        @else
            <button type="submit" name="skip_errors" value="1" class="btn-primary"
                    @disabled($good->isEmpty())>
                استورد الصفوف الصحيحة فقط ({{ number_format($good->count()) }})
            </button>
        @endif
    </form>

    <a href="{{ $back }}" class="btn-ghost">ارفع ملفاً آخر</a>

    <p class="ms-auto text-xs text-ink-500">
        التاجر: <span class="font-semibold text-ink-700">{{ $merchant->business_name }}</span>
    </p>
</div>
