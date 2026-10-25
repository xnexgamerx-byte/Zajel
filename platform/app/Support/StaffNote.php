<?php

namespace App\Support;

use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * «ملاحظة للموظّفين» (docs/plan/83): سطرٌ واحدٌ على الشحنة تكتبه الموظّفة — «اتّصلتُ ولم يردّ»،
 * «أكّد الزبون موعد الليل» — فتعرف زميلتها من القائمة نفسها أنّه اتُّصل به ومن اتّصل ومتى. الأحدث
 * يحلّ محلّ ما قبله، وكلّ ما كُتب يبقى في سجلّ الشحنة. للموظّفين وحدهم: ليست في أحداث التاجر ولا
 * التتبّع العامّ ولا تطبيق المندوب.
 */
final class StaffNote
{
    public const MAX = 255;

    /** @return array{text: string, by: ?string, at: ?string}|null */
    public static function json(Shipment $shipment): ?array
    {
        return filled($shipment->staff_note) ? [
            'text' => (string) $shipment->staff_note,
            'by' => $shipment->staff_note_by,
            'at' => $shipment->staff_note_at?->toIso8601String(),
        ] : null;
    }

    /** تكتبها، أو تمسحها بنصٍّ فارغ — وتُسجَّل في سجلّ الشحنة باسمها */
    public static function write(Shipment $shipment, ?string $text, User $user, ?string $ip = null): void
    {
        $text = trim((string) $text);

        DB::transaction(function () use ($shipment, $text, $user, $ip) {
            $shipment->forceFill($text === ''
                ? ['staff_note' => null, 'staff_note_by' => null, 'staff_note_at' => null]
                : ['staff_note' => $text, 'staff_note_by' => $user->name, 'staff_note_at' => now()])->save();

            ShipmentEvent::create([
                'shipment_id' => $shipment->id,
                'from_status' => $shipment->status->value,
                'to_status' => $shipment->status->value,
                'event_type' => 'staff_note',
                'actor_type' => 'user',
                'actor_id' => $user->id,
                'actor_name' => $user->name,
                'courier_id' => $shipment->delivery_courier_id,
                'note' => $text === '' ? 'مُسحت الملاحظة' : $text,
                'ip' => $ip,
            ]);
        });
    }
}
