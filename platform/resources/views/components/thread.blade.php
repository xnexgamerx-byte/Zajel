@props(['messages', 'mine', 'fileRoute'])

{{--
  سطور المحادثة. «mine» جهة القارئ (staff أو merchant): سطوره إلى جهة
  النهاية (اليسار في صفحةٍ يمينية) وسطور الطرف الآخر إلى جهة البداية —
  كما يعرضها واتساب بالعربية، وهو ما اعتاده كل تاجرٍ هنا.
  «fileRoute» مسار ملفّات الرسائل من جهة القارئ: الصورة مصغّرةٌ تُفتح
  كاملة، وغيرها رابطٌ باسمه وحجمه.
--}}
<ol class="space-y-3" aria-label="الرسائل">
    @foreach ($messages as $message)
        @php $isMine = $message->author === $mine; @endphp
        <li class="flex {{ $isMine ? 'justify-end' : 'justify-start' }}">
            <div class="max-w-[85%] rounded-2xl px-4 py-2.5 {{ $isMine ? 'bg-[var(--brand)] text-white' : 'border border-ink-200 bg-white' }}">
                @if ($message->hasAttachment() && ! $message->attachmentAvailable())
                    <p class="mb-1.5 text-xs {{ $isMine ? 'text-white/75' : 'text-ink-500' }}">
                        أُرفق «{{ $message->attachment_name }}» ولم يعد متوفّراً.
                    </p>
                @elseif ($message->hasAttachment())
                    @php $file = route($fileRoute, [$message->conversation_id, $message->id]); @endphp
                    @if ($message->attachmentIsImage())
                        <a href="{{ $file }}" target="_blank" rel="noopener" class="mb-1.5 block">
                            <img src="{{ $file }}" alt="{{ $message->attachment_name }}" loading="lazy"
                                 class="max-h-60 w-auto max-w-full rounded-lg bg-white/10 object-contain">
                        </a>
                    @else
                        <a href="{{ $file }}" class="mb-1.5 flex items-center gap-2 rounded-lg px-3 py-2 text-sm {{ $isMine ? 'bg-white/15 hover:bg-white/25' : 'bg-ink-50 hover:bg-ink-100' }}">
                            <x-icon name="file" class="size-5 shrink-0" />
                            <span class="min-w-0 flex-1 truncate font-medium">{{ $message->attachment_name }}</span>
                            <span class="num shrink-0 text-xs {{ $isMine ? 'text-white/75' : 'text-ink-500' }}">{{ $message->attachmentSizeLabel() }}</span>
                        </a>
                    @endif
                @endif
                @if ($message->body !== '')
                    <p class="whitespace-pre-line text-sm leading-relaxed">{{ $message->body }}</p>
                @endif
                <p class="mt-1 text-[11px] {{ $isMine ? 'text-white/75' : 'text-ink-400' }}">
                    {{ $message->author_name }} ·
                    <span class="num">{{ $message->created_at->format('m-d H:i') }}</span>
                </p>
            </div>
        </li>
    @endforeach
</ol>
