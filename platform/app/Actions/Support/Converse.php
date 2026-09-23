<?php

namespace App\Actions\Support;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * المحادثات: فتحٌ وردٌّ وإغلاق.
 *
 * الرسائل إضافةٌ فقط — لا تُعدَّل ولا تُحذف، فالمحادثة سجلُّ ما قيل.
 * والعلَمان (غير مقروء عندنا / عنده) يُقلبان مع كل سطر تحت قفل الصفّ،
 * فردّان متزامنان لا يتركان المحادثة «مقروءة» وفيها سطرٌ لم يُقرأ.
 */
class Converse
{
    public const MERCHANT = 'merchant';

    public const STAFF = 'staff';

    public function start(Merchant $merchant, string $subject, string $body, User $actor, string $author, ?string $shipmentNumber = null): Conversation
    {
        $shipment = $this->resolveShipment($merchant, $shipmentNumber);

        return DB::transaction(function () use ($merchant, $subject, $body, $actor, $author, $shipment) {
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

            $this->write($conversation, $author, $body, $actor);

            return $conversation;
        });
    }

    public function reply(Conversation $conversation, string $body, User $actor, string $author): ConversationMessage
    {
        return DB::transaction(function () use ($conversation, $body, $actor, $author) {
            $fresh = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);

            $message = $this->write($fresh, $author, $body, $actor);

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

    protected function write(Conversation $conversation, string $author, string $body, User $actor): ConversationMessage
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'لا تُرسَل رسالة فارغة.']);
        }

        if (! in_array($author, [self::MERCHANT, self::STAFF], true)) {
            throw ValidationException::withMessages(['body' => 'كاتبٌ غير معروف.']);
        }

        return ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'author'          => $author,
            'user_id'         => $actor->id,
            'author_name'     => $actor->name,
            'body'            => $body,
        ]);
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
