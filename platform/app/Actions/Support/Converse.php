<?php

namespace App\Actions\Support;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\MerchantHours;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * المحادثات: فتحٌ وردٌّ وإغلاق.
 *
 * الرسائل إضافةٌ فقط — لا تُعدَّل ولا تُحذف، فالمحادثة سجلُّ ما قيل.
 * والعلَمان (غير مقروء عندنا / عنده) يُقلبان مع كل سطر تحت قفل الصفّ،
 * فردّان متزامنان لا يتركان المحادثة «مقروءة» وفيها سطرٌ لم يُقرأ.
 *
 * وللرسالة ملفٌّ واحد إن شاء كاتبها — صورة تلفٍ أو وصلٍ أو كشف — في
 * التخزين الخاصّ، يُقدَّم لطرفي المحادثة وحدهما. ورسالةٌ هي ملفٌّ بلا نصّ مقبولة.
 *
 * والتاجر يراسل في ساعات الشركة وحدها (MerchantHours، docs/plan/39): يُفحص هنا
 * لا في النموذج، فلا بابَ آخر يُرسل منه خارجها.
 */
class Converse
{
    public const MERCHANT = 'merchant';

    public const STAFF = 'staff';

    /** ما يُقبل ملفّاً: صورٌ حقيقية وPDF — لا SVG ولا ما يُنفَّذ في المتصفّح */
    public const ATTACHMENT_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /** قاعدة التحقّق نفسها في كل نموذج يرفع ملفّاً */
    public const ATTACHMENT_RULE = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];

    public function start(Merchant $merchant, string $subject, string $body, User $actor, string $author, ?string $shipmentNumber = null, ?UploadedFile $file = null): Conversation
    {
        $this->withinHours($author);
        $shipment = $this->resolveShipment($merchant, $shipmentNumber);

        return DB::transaction(function () use ($merchant, $subject, $body, $actor, $author, $shipment, $file) {
            $conversation = Conversation::create([
                'merchant_id'     => $merchant->id,
                'shipment_id'     => $shipment?->id,
                'subject'         => trim($subject),
                'status'          => 'open',
                'last_author'     => $author,
                'last_message_at' => now(),
                'staff_unread'    => $author === self::MERCHANT,
                'merchant_unread' => $author === self::STAFF,
            ]);

            $this->write($conversation, $author, $body, $actor, $file);

            return $conversation;
        });
    }

    public function reply(Conversation $conversation, string $body, User $actor, string $author, ?UploadedFile $file = null): ConversationMessage
    {
        $this->withinHours($author);

        return DB::transaction(function () use ($conversation, $body, $actor, $author, $file) {
            $fresh = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);

            $message = $this->write($fresh, $author, $body, $actor, $file);

            $fresh->forceFill([
                // سطرٌ جديد في محادثة مغلقة سؤالٌ جديد: تُفتح من تلقاء نفسها
                'status'          => 'open',
                'last_author'     => $author,
                'last_message_at' => $message->created_at,
                'staff_unread'    => $author === self::MERCHANT,
                'merchant_unread' => $author === self::STAFF,
            ])->save();

            $conversation->setRawAttributes($fresh->getAttributes(), true);

            return $message;
        });
    }

    public function close(Conversation $conversation): void
    {
        $conversation->forceFill(['status' => 'closed', 'staff_unread' => false])->save();
    }

    public function reopen(Conversation $conversation): void
    {
        $conversation->forceFill(['status' => 'open'])->save();
    }

    /** فتحُ المحادثة قراءتُها من جهة الفاتح. */
    public function markRead(Conversation $conversation, string $side): void
    {
        $column = $side === self::STAFF ? 'staff_unread' : 'merchant_unread';

        if ($conversation->{$column}) {
            // تحديثٌ مشروط: لا يمحو علَماً رفعه ردٌّ وصل للتوّ بعد قراءتنا
            Conversation::whereKey($conversation->id)
                ->where('last_message_at', '<=', $conversation->last_message_at)
                ->update([$column => false]);

            $conversation->setAttribute($column, false);
        }
    }

    /** رسالة التاجر خارج ساعات الشركة تُرَدّ بموعد فتحها */
    protected function withinHours(string $author): void
    {
        if ($author === self::MERCHANT && ! MerchantHours::isOpen()) {
            throw ValidationException::withMessages(['body' => MerchantHours::closedMessage()]);
        }
    }

    protected function write(Conversation $conversation, string $author, string $body, User $actor, ?UploadedFile $file = null): ConversationMessage
    {
        $body = trim($body);

        if ($body === '' && ! $file) {
            throw ValidationException::withMessages(['body' => 'لا تُرسَل رسالة فارغة.']);
        }

        if (! in_array($author, [self::MERCHANT, self::STAFF], true)) {
            throw ValidationException::withMessages(['body' => 'كاتبٌ غير معروف.']);
        }

        // النوع من محتوى الملف لا من اسمه ولا مما قاله المتصفّح
        $mime = $file?->getMimeType();

        if ($file && ! in_array($mime, self::ATTACHMENT_MIMES, true)) {
            throw ValidationException::withMessages(['attachment' => 'الملف صورةٌ (JPG أو PNG أو WEBP) أو PDF.']);
        }

        $path = $file?->store('attachments/'.$conversation->company_id, 'local');

        try {
            return ConversationMessage::create([
                'conversation_id' => $conversation->id,
                'author'          => $author,
                'user_id'         => $actor->id,
                'author_name'     => $actor->name,
                'body'            => $body,
                'attachment_path' => $path ?: null,
                'attachment_name' => $file ? $this->displayName($file) : null,
                'attachment_mime' => $file ? $mime : null,
                'attachment_size' => $file?->getSize(),
            ]);
        } catch (\Throwable $e) {
            // لا ملفّ يتيم في التخزين لرسالةٍ لم تُكتب
            if ($path) {
                Storage::disk('local')->delete($path);
            }

            throw $e;
        }
    }

    /** اسم الملف كما سمّاه صاحبه، للعرض وحده: بلا مسار ولا محارف تحكّم */
    protected function displayName(UploadedFile $file): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', (string) $file->getClientOriginalName())));

        return mb_substr(trim((string) $name), 0, 160) ?: 'ملف';
    }

    /** رقم الوصل يُقبل إن كان للتاجر نفسه — وإلا فشحنة غيره لا تُعلَّق بمحادثته. */
    protected function resolveShipment(Merchant $merchant, ?string $number): ?Shipment
    {
        $number = trim((string) $number);

        if ($number === '') {
            return null;
        }

        $shipment = Shipment::query()
            ->where('merchant_id', $merchant->id)
            ->where(fn ($q) => $q->where('number', $number)->orWhere('barcode', $number))
            ->first();

        if (! $shipment) {
            throw ValidationException::withMessages(['shipment_number' => "لا وصل بالرقم {$number} بين شحنات {$merchant->business_name}."]);
        }

        return $shipment;
    }
}
