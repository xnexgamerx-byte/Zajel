{{-- أرقام الفترة كما تُحفظ عند الإقفال: الربح والتحصيل والالتزامات والسحب، وميزانها --}}
@php($f = $figures)
<div class="mb-4 grid grid-cols-2 gap-3 text-sm md:grid-cols-3 xl:grid-cols-4">
    @foreach ([
        ['إيراد الأجور', $f['revenue']['total'] ?? 0, 'text-info-700'],
        ['التكاليف', $f['costs']['total'] ?? 0, 'text-bad-700'],
        ['صافي الربح', $f['net']['total'] ?? 0, ($f['net']['total'] ?? 0) >= 0 ? 'text-profit-700' : 'text-bad-700'],
        ['المحصَّل نقداً', $f['realized']['total'] ?? 0, 'text-ok-700'],
        ['غير المحصَّل (عند الإقفال)', $f['unrealized']['total'] ?? 0, 'text-warn-700'],
        ['المسحوب في الفترة', $f['drawn'] ?? 0, ''],
        ['المحتجز (عند الإقفال)', $f['retained']['total'] ?? 0, ''],
        ['المتاح للسحب (عند الإقفال)', $f['available']['total'] ?? 0, 'text-profit-700'],
        ['مستحقّات التجّار', $f['payables']['total'] ?? 0, ''],
        ['مصروفات مستحقّة', $f['available']['expenses_due'] ?? 0, ''],
        ['المسلَّمة', $f['delivered']['delivered'] ?? 0, ''],
        ['الراجع المغلق', $f['delivered']['returned'] ?? 0, ''],
    ] as [$label, $value, $tone])
        <div class="rounded-xl bg-ink-50 p-3">
            <div class="text-xs text-ink-500">{{ $label }}</div>
            <div class="num mt-1 font-bold {{ $tone }}">{{ number_format($value) }}</div>
        </div>
    @endforeach
</div>
@if (! empty($f['trial']))
    <details class="mb-2">
        <summary class="cursor-pointer text-sm font-semibold text-[var(--brand)]">ميزان المراجعة للفترة</summary>
        <div class="mt-2 overflow-x-auto">
            <table class="tbl text-sm">
                <thead><tr><th>الحساب</th><th>أوّل المدّة</th><th>مدين</th><th>دائن</th><th>آخر المدّة</th></tr></thead>
                <tbody>
                    @foreach ($f['trial'] as $row)
                        <tr>
                            <td><span class="num text-xs text-ink-500">{{ $row['account'] }}</span> {{ $row['name'] }}</td>
                            <td class="num">{{ number_format($row['opening']) }}</td>
                            <td class="num">{{ number_format($row['debit']) }}</td>
                            <td class="num">{{ number_format($row['credit']) }}</td>
                            <td class="num font-semibold">{{ number_format($row['closing']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif
