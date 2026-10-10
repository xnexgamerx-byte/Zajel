<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\User;

/**
 * إشعارٌ لتاجرٍ بعينه في جرس تطبيقه وبوابته (docs/plan/61): «تغيّر مبلغ وصلك».
 *
 * يُطفأ ويُشغَّل من «بيانات الشركة» (notify.amount_change)، وهو شغّالٌ ما لم يُطفأ. ولا يُشعَر
 * التاجر بما غيّره هو بنفسه.
 */
final class MerchantNotice
{
    public static function amountChangeEnabled(?Company $company): bool
    {
        return (bool) ($company?->setting('notify.amount_change', true) ?? true);
    }

    public static function amountChanged(Shipment $shipment, int $from, int $to, ?User $actor): void
    {
        if ($from === $to || ($actor && ! $actor->isStaff())) {
            return;
        }

        $company = Company::find($shipment->company_id);

        if (! self::amountChangeEnabled($company)) {
            return;
        }

        Announcement::create([
            'company_id' => $shipment->company_id,
            'audience' => 'merchants',
            'merchant_id' => $shipment->merchant_id,
            'branch_id' => null,
            'title' => "تغيّر مبلغ الوصل {$shipment->number}",
            'body' => 'المبلغ صار '.number_format($to).' د.ع بدل '.number_format($from).' د.ع'
                .($shipment->recipient_name && $shipment->recipient_name !== Shipment::UNNAMED_RECIPIENT ? " — زبونك {$shipment->recipient_name}" : '')
                .'. وتحدّث في وصلك وحسابك.',
            'created_by_user_id' => $actor?->id,
        ]);
    }
}
