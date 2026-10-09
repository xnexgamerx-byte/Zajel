<?php

namespace App\Actions\Support;

use App\Models\Courier;
use App\Models\CourierMessage;
use App\Models\CourierThread;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * محادثة الكول سنتر مع المندوب (docs/plan/38): محادثةٌ واحدة لكل مندوب، والسطر يحمل
 * رقم شحنته إن كان عنها. والعلَمان (غير مقروء عند المكتب / عند المندوب) يُقلبان مع كل
 * سطرٍ تحت قفل الصفّ، كما في محادثات التجّار (Converse).
 */
class CourierChat
{
    public const STAFF = 'staff';

    public const COURIER = 'courier';

    public function thread(Courier $courier): CourierThread
    {
        return CourierThread::firstOrCreate(['courier_id' => $courier->id]);
    }

    public function send(Courier $courier, string $body, User $actor, string $author, ?Shipment $shipment = null): CourierMessage
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'لا تُرسَل رسالة فارغة.']);
        }

        if (! in_array($author, [self::STAFF, self::COURIER], true)) {
            throw ValidationException::withMessages(['body' => 'كاتبٌ غير معروف.']);
        }

        return DB::transaction(function () use ($courier, $body, $actor, $author, $shipment) {
            $thread = CourierThread::query()->lockForUpdate()->findOrFail($this->thread($courier)->id);

            $message = CourierMessage::create([
                'courier_thread_id' => $thread->id,
                'author'            => $author,
                'user_id'           => $actor->id,
                'author_name'       => $actor->name,
                'shipment_id'       => $shipment?->id,
                'body'              => mb_substr($body, 0, 2000),
                'created_at'        => now(),
            ]);

            $thread->forceFill([
                'last_author'     => $author,
                'last_message_at' => $message->created_at,
                'staff_unread'    => $author === self::COURIER,
                'courier_unread'  => $author === self::STAFF,
            ])->save();

            return $message;
        });
    }

    /** فتحُ المحادثة قراءتُها من جهة الفاتح — ولا يمحو علَماً رفعه سطرٌ وصل بعد القراءة. */
    public function markRead(CourierThread $thread, string $side): void
    {
        $column = $side === self::STAFF ? 'staff_unread' : 'courier_unread';

        if ($thread->{$column}) {
            CourierThread::whereKey($thread->id)
                ->where('last_message_at', '<=', $thread->last_message_at)
                ->update([$column => false]);

            $thread->setAttribute($column, false);
        }
    }
}
