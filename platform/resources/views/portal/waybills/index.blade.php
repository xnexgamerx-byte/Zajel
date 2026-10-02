@extends('layouts.portal')
@section('title', 'وصولات للطباعة')

@section('content')
<div class="mb-5">
    <h1 class="page-title">وصولات للطباعة</h1>
    <p class="mt-1 text-sm text-ink-500">
        اطبع وصولاتٍ بأرقامٍ لك على طابعة الملصقات، واكتب على كل وصلٍ بيدك اسم الزبون وهاتفه وعنوانه والمبلغ، والصقه على الطرد.
        حين يصل الطرد يُمسح الوصل وتُدخَل شحنته، فتجدها في «شحناتي» وتبحث عنها برقم الوصل نفسه.
    </p>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <form method="POST" action="{{ route('portal.waybills.store') }}" class="card space-y-4 p-5 lg:self-start">
        @csrf
        <h2 class="card-title">وصولات جديدة</h2>

        <div>
            <label class="field-label" for="size">كم وصلاً؟</label>
            <input id="size" name="size" type="number" min="1" max="{{ \App\Models\WaybillBook::MAX_SIZE }}" step="1"
                   value="{{ old('size', 50) }}" class="field-input num w-32" required>
            <p class="field-hint">حتى <span class="num">{{ \App\Models\WaybillBook::MAX_SIZE }}</span> في المرّة.</p>
            @error('size') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <fieldset>
            <legend class="field-label">مقاس ملصقات طابعتك</legend>
            <div class="grid grid-cols-2 gap-3">
                @foreach (\App\Models\WaybillBook::PRINT_SIZES as $key => [$w, $h])
                    <label class="flex cursor-pointer flex-col items-center gap-2 rounded-xl border border-ink-200 p-3 has-[:checked]:border-[var(--brand)] has-[:checked]:bg-ink-50">
                        <input type="radio" name="print_size" value="{{ $key }}" class="sr-only" required
                               @checked(old('print_size', array_key_first(\App\Models\WaybillBook::PRINT_SIZES)) === $key)>
                        {{-- الملصق مصغّراً بنسبته --}}
                        <span class="block rounded border-2 border-ink-400 bg-white" style="width: {{ $w * 0.4 }}px; height: {{ $h * 0.4 }}px" aria-hidden="true"></span>
                        <span class="text-sm font-semibold"><span class="num">{{ $w }}×{{ $h }}</span> ملم</span>
                    </label>
                @endforeach
            </div>
            @error('print_size') <p class="field-error">{{ $message }}</p> @enderror
        </fieldset>

        <button type="submit" class="btn-primary w-full">اطبع</button>
    </form>

    <section class="card overflow-hidden lg:col-span-2">
        <div class="border-b border-ink-100 px-5 py-4">
            <h2 class="card-title">دفاتري</h2>
            <p class="card-hint">أعِد طباعة أيّ دفتر بأيّ مقاس: ما استُعمل منه لا يُطبع ثانيةً.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>الأرقام</th><th>استُعمل</th><th>طُبع</th><th>اطبع ثانيةً</th></tr>
                </thead>
                <tbody>
                    @forelse ($books as $book)
                        <tr>
                            <td>
                                <span class="num font-semibold whitespace-nowrap">{{ $book->firstCode() }}–{{ $book->lastCode() }}</span>
                                <span class="block text-xs text-ink-500">{{ \App\Support\Arabic::waybills($book->size) }}</span>
                            </td>
                            <td class="text-sm whitespace-nowrap">
                                <span class="num font-semibold">{{ number_format($book->used_count) }}</span>
                                <span class="text-ink-500">من <span class="num">{{ number_format($book->size) }}</span></span>
                            </td>
                            <td class="num text-xs whitespace-nowrap text-ink-500">{{ ($book->printed_at ?? $book->created_at)->format('Y-m-d') }}</td>
                            <td class="whitespace-nowrap">
                                @if ($book->used_count < $book->size)
                                    @foreach (\App\Models\WaybillBook::PRINT_SIZES as $key => [$w, $h])
                                        <a href="{{ route('portal.waybills.print', ['book' => $book, 'size' => $key]) }}"
                                           class="text-sm font-semibold text-[var(--brand)] hover:underline"><span class="num">{{ $w }}×{{ $h }}</span></a>@if (! $loop->last)<span class="px-1 text-ink-300">·</span>@endif
                                    @endforeach
                                @else
                                    <span class="text-sm text-ink-500">استُعمل كلّه</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-ink-500">لم تطبع وصولاتٍ بعد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($books->hasPages())
            <div class="border-t border-ink-100 px-4 py-3">{{ $books->links() }}</div>
        @endif
    </section>
</div>
@endsection
