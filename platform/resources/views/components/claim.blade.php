@props(['subject', 'id', 'claim' => null])
{{--
    «من فتح الطلب يعالجه» (docs/plan/73): «أعالجها» تحجزه لها، وزميلتها ترى اسمها ولا تعالجه حتى تحسمه
    أو تفلته أو تمضي ربع ساعةٍ بلا عمل.
--}}
@php $other = \App\Actions\Support\Claims::heldByOther($claim, auth()->user()); @endphp
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2 text-xs']) }} data-claim>
    @if ($other)
        <span class="chip chip-warn"><x-icon name="user" class="size-4"/> {{ \App\Actions\Support\Claims::message($claim) }}</span>
    @elseif ($claim)
        <span class="chip chip-ok"><x-icon name="user" class="size-4"/> تعالجها أنت</span>
        <form method="POST" action="{{ route('claims.release', [$subject, $id]) }}">
            @csrf
            <button type="submit" class="btn-ghost py-1 text-xs">أفلتها لزميلاتك</button>
        </form>
    @else
        <form method="POST" action="{{ route('claims.take', [$subject, $id]) }}">
            @csrf
            <button type="submit" class="btn-primary py-1 text-xs"><x-icon name="user" class="size-4"/> أعالجها</button>
        </form>
    @endif
</div>
