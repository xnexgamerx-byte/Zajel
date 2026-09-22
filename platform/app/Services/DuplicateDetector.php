<?php

namespace App\Services;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Illuminate\Support\Collection;

/**
 * كشف الإيصالات المكرّرة.
 *
 * التاجر يرفع ملفه مرّتين، أو يُدخل الطلب يدوياً ثم يرفعه في ملف. الشحنة
 * المزدوجة تُحاسَب مرّتين وتُوزَّع مرّتين ويُطالَب بها الزبون مرّتين —
 * وتُكتشف عادةً بعد شهر عند مطابقة كشف الحساب.
 *
 * والبصمة هنا: تاجر + هاتف المستلم + المبلغ. لا رقم الطلب — لأن أكثر
 * التكرار يقع حين لا يُرسَل رقم طلب أصلاً.
 */
class DuplicateDetector
{
    /** نافذة الاشتباه: طلب ثانٍ لنفس الزبون بنفس المبلغ بعدها أمر عاديّ. */
    public const WINDOW_DAYS = 7;

    public function hash(int $merchantId, string $phone, int $amount): string
    {
        return sha1($merchantId.'|'.preg_replace('/\D+/', '', $phone).'|'.$amount);
    }

    public function hashFor(Shipment $shipment): string
    {
        return $this->hash(
            (int) $shipment->merchant_id,
            (string) $shipment->recipient_phone,
            (int) $shipment->cod_amount,
        );
    }

    /** الشحنة السابقة المشتبه بأن هذه تكرارٌ لها. */
    public function findOriginal(Shipment $shipment): ?Shipment
    {
        return Shipment::query()
            ->where('dedupe_hash', $this->hashFor($shipment))
            ->whereKeyNot($shipment->id)
            ->whereNull('duplicate_of_id')
            ->where('created_at', '>=', $shipment->created_at->copy()->subDays(self::WINDOW_DAYS))
            ->where('status', '!=', ShipmentStatus::Cancelled->value)
            ->orderBy('id')
            ->first();
    }

    /** المشتبهات المفتوحة — لم تُمسَح ولم تُلغَ. */
    public function pending(): Collection
    {
        return Shipment::query()
            ->whereNotNull('duplicate_of_id')
            ->whereNull('duplicate_cleared_at')
            ->where('status', '!=', ShipmentStatus::Cancelled->value)
            ->with(['merchant:id,business_name', 'governorate:id,name_ar'])
            ->latest('id')
            ->get();
    }
}
