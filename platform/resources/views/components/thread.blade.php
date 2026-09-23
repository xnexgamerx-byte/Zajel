@props(['messages', 'mine'])

{{--
  سطور المحادثة. «mine» جهة القارئ (staff أو merchant): سطوره إلى جهة
  النهاية (اليسار في صفحةٍ يمينية) وسطور الطرف الآخر إلى جهة البداية —
  كما يعرضها واتساب بالعربية، وهو ما اعتاده كل تاجرٍ هنا.
--}}
<ol class="space-y-3" aria-label="الرسائل">
    @foreach ($messages as $message)
        @php $isMine = $message->author === $mine; @endphp
        <li class="flex {{ $isMine ? 'justify-end' : 'justify-start' }}">
            <div class="max-w-[85%] rounded-2xl px-4 py-2.5 {{ $isMine ? 'bg-[var(--brand)] text-white' : 'border border-ink-200 bg-white' }}">
                <p class="whitespace-pre-line text-sm leading-relaxed">{{ $message->body }}</p>
                <p class="mt-1 text-[11px] {{ $isMine ? 'text-white/75' : 'text-ink-400' }}">
                    {{ $message->author_name }} ·
                    <span class="num">{{ $message->created_at->format('m-d H:i') }}</span>
                </p>
            </div>
        </li>
    @endforeach
</ol>
