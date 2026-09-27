@props(['period' => null])

{{-- مرشّح التقارير: المدّة إن كان للتقرير مدّة، وما يخصّه في الوسط --}}
<form method="GET" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
    @if ($period)
        <div>
            <label class="field-label" for="from">من</label>
            <input id="from" name="from" type="date" class="field-input" value="{{ $period->from->toDateString() }}">
        </div>
        <div>
            <label class="field-label" for="to">إلى</label>
            <input id="to" name="to" type="date" class="field-input" value="{{ $period->to->toDateString() }}">
        </div>
    @endif
    {{ $slot }}
    <button type="submit" class="btn-primary">طبّق</button>
</form>
