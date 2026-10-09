{{-- حذف الكشف المُقفَل في يومه وحده، بحركاتٍ معاكسة (docs/plan/38). $route: مسار الحذف، $ability: صلاحيته، $effect: ما يعود --}}
@php $undo = \App\Actions\Money\UndoWithinDay::class; @endphp
@if ($settlement->status === 'cancelled')
    <section class="card border-bad-200 bg-bad-50 p-5 text-sm">
        <h2 class="mb-2 font-bold text-bad-700">الكشف ملغى</h2>
        <dl class="space-y-1 text-bad-700">
            <div class="flex justify-between gap-3"><dt>أُلغي في</dt><dd class="num">{{ $settlement->cancelled_at?->format('Y-m-d H:i') }}</dd></div>
            <div class="flex justify-between gap-3"><dt>بيد</dt><dd>{{ \App\Models\User::find($settlement->cancelled_by_user_id)?->name ?? '—' }}</dd></div>
            <div><dt class="inline">السبب:</dt> <dd class="inline">{{ $settlement->cancel_reason }}</dd></div>
        </dl>
        <p class="mt-2 text-xs text-bad-700">عُكس كل ما قيّده في الحسابات والصندوق، وعادت شحناته لكشفٍ جديد.</p>
    </section>
@elseif ($undo::open($settlement->confirmed_at))
    @can($ability)
        <form method="POST" action="{{ $route }}" class="card space-y-3 p-5">
            @csrf
            <h2 class="text-sm font-bold">حذف الكشف</h2>
            <p class="text-xs text-ink-500">
                {{ $effect }} يُحذف حتى <span class="num">{{ $undo::until($settlement->confirmed_at)->format('Y-m-d H:i') }}</span>،
                وبعدها لا يُحذف ولا يُعدَّل.
            </p>
            <input name="reason" type="text" required maxlength="255" class="field-input" placeholder="سبب الحذف">
            @error('reason') <p class="field-error">{{ $message }}</p> @enderror
            @error('settlement') <p class="field-error">{{ $message }}</p> @enderror
            <button type="submit" class="btn-danger w-full"
                    data-confirm="يُحذف كشف {{ $settlement->code }}؟ تُعكس حركاته كلّها وتعود شحناته لكشفٍ جديد.">
                حذف الكشف
            </button>
        </form>
    @endcan
@else
    <p class="text-xs text-ink-500">مضت أربعٌ وعشرون ساعة على إقفال الكشف: لا يُحذف ولا يُعدَّل، ويُصحَّح بحركةٍ جديدة.</p>
@endif
