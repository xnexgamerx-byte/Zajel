@extends('layouts.print', ['back' => route('branch-accounts.statement', array_merge($period->query(), ['branch_id' => $branch?->id]))])
@section('title', 'كشف حساب '.($branch?->name ?? 'الفرع'))
@section('docTitle', 'كشف حساب فرع')
@section('subtitle', ($branch?->name ?? '—').' — من '.$period->from->format('Y-m-d').' إلى '.$period->to->format('Y-m-d'))

@section('content')
    @include('tenant.branch_accounts._statement')
@endsection

@section('signatures')
    <div class="flex-1">
        <div>أعدّه: {{ auth()->user()->name }}</div>
        <div class="mt-8 border-t border-ink-400 pt-1">التوقيع</div>
    </div>
    <div class="flex-1">
        <div>راجعه المحاسب: ..................</div>
        <div class="mt-8 border-t border-ink-400 pt-1">التوقيع</div>
    </div>
    <div class="flex-1">
        <div>اعتمده مدير الفرع: ..................</div>
        <div class="mt-8 border-t border-ink-400 pt-1">التوقيع</div>
    </div>
@endsection
