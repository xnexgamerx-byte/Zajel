@extends('layouts.app')
@section('title', 'ما رفعه التجّار من بواباتهم')

@section('content')
@include('tenant.reports.reference._head', ['title' => 'ما رفعه التجّار من بواباتهم', 'blurb' => '«المُدخلة عبر تطبيق العميل» و«رفعها العملاء واستُلمت»: كم أُنشئ من البوابة كل يوم، وكم استلمه المندوب منه. '.$period->label()])

<x-report-period :period="$period">
    <div class="min-w-48">
        <label class="field-label" for="merchant_id">التاجر</label>
        <select id="merchant_id" name="merchant_id" class="field-input" data-searchable>
            <option value="">الكل</option>
            @foreach ($merchants as $merchant)
                <option value="{{ $merchant->id }}" @selected($merchantId === $merchant->id)>{{ $merchant->business_name }}</option>
            @endforeach
        </select>
    </div>
</x-report-period>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>يوم الإنشاء</th><th>أُنشئت</th><th>استُلمت</th><th>ألغيت</th><th>تنتظر الاستلام</th></tr></thead>
            <tbody>
                @forelse ($days as $day)
                    <tr>
                        <td class="num">{{ $day->day }}</td>
                        <td class="num font-semibold">{{ number_format($day->created) }}</td>
                        <td class="num text-ok-700">{{ number_format($day->picked) }}</td>
                        <td class="num text-ink-500">{{ number_format($day->cancelled) }}</td>
                        <td class="num {{ ($day->created - $day->picked - $day->cancelled) > 0 ? 'text-warn-700 font-semibold' : 'text-ink-400' }}">{{ number_format(max(0, $day->created - $day->picked - $day->cancelled)) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-ink-500">لم يرفع التجّار شيئاً من بواباتهم في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
            @if ($days->isNotEmpty())
                <tfoot>
                    <tr class="border-t-2 border-ink-200 font-semibold">
                        <td>المجموع</td>
                        <td class="num">{{ number_format($days->sum('created')) }}</td>
                        <td class="num">{{ number_format($days->sum('picked')) }}</td>
                        <td class="num">{{ number_format($days->sum('cancelled')) }}</td>
                        <td class="num">{{ number_format(max(0, $days->sum('created') - $days->sum('picked') - $days->sum('cancelled'))) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
