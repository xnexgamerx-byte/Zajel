<?php

namespace App\Support;

use App\Enums\ShipmentStatus;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Push\MerchantPush;

/**
 * إشعارٌ لتاجرٍ بعينه في جرس تطبيقه وبوابته (docs/plan/61، 81): «تغيّر مبلغ وصلك»، و«وصلت شحنتك»،
 * و«عولجت شحنتك» بقرارها — إعادة توصيل أو تأجيل أو راجع مؤكد.
 *
 * كلٌّ يُطفأ ويُشغَّل من «بيانات الشركة» (notify.amount_change · notify.delivered · notify.processed)،
 * وهو شغّالٌ ما لم يُطفأ. ولا يُشعَر التاجر بما فعله هو بنفسه.
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

        self::announce([
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

    public static function deliveredEnabled(?Company $company): bool
    {
        return (bool) ($company?->setting('notify.delivered', true) ?? true);
    }

    public static function processedEnabled(?Company $company): bool
    {
        return (bool) ($company?->setting('notify.processed', true) ?? true);
    }

    /** «وصلت شحنتك» (docs/plan/81): سُلّمت، أو سُلّم بعضها — بما قُبض */
    public static function delivered(Shipment $shipment): void
    {
        if ($shipment->merchant_id === null || ! self::deliveredEnabled(Company::find($shipment->company_id))) {
            return;
        }

        $partial = $shipment->status === ShipmentStatus::PartiallyDelivered;
        $collected = (int) $shipment->collected_amount;

        self::notify($shipment, $partial ? "وصلت شحنتك {$shipment->number} جزئياً" : "وصلت شحنتك {$shipment->number}", ($partial
            ? 'سُلّم بعضها وقُبض '.number_format($collected).' د.ع من '.number_format((int) $shipment->cod_amount).'، والباقي يرجع إليك'
            : 'سُلّمت وقُبض '.number_format($collected).' د.ع').self::to($shipment).'.');
    }

    /**
     * «عولجت شحنتك» (docs/plan/81): قرار الكول سنتر فيما لم يُسلَّم — تعود للتوصيل، أو تؤجَّل إلى موعد،
     * أو ترجع إليه.
     */
    public static function processed(Shipment $shipment, string $action, ?string $until = null): void
    {
        if ($shipment->merchant_id === null || ! self::processedEnabled(Company::find($shipment->company_id))) {
            return;
        }

        [$title, $body] = match ($action) {
            'redeliver' => ["إعادة توصيل شحنتك {$shipment->number}", 'اتّصلنا بالزبون، وتخرج إليه ثانيةً'],
            'postpone' => ["أُجّلت شحنتك {$shipment->number}", 'اتّفقنا مع الزبون على التسليم'.($until ? ' يوم '.$until : ' لاحقاً')],
            default => ["شحنتك {$shipment->number} راجعة إليك", 'تأكّد أنّ الزبون لا يستلمها، فترجع إليك مع الراجع'],
        };

        self::notify($shipment, $title, $body.self::to($shipment).'.');
    }

    private static function to(Shipment $shipment): string
    {
        return $shipment->recipient_name && $shipment->recipient_name !== Shipment::UNNAMED_RECIPIENT ? " — زبونك {$shipment->recipient_name}" : '';
    }

    private static function notify(Shipment $shipment, string $title, string $body): void
    {
        self::announce([
            'company_id' => $shipment->company_id,
            'audience' => 'merchants',
            'merchant_id' => $shipment->merchant_id,
            'branch_id' => null,
            'title' => $title,
            'body' => $body,
            'created_by_user_id' => null,
        ]);
    }

    /** الجرس أوّلاً، ثم هواتف التاجر (docs/plan/82) */
    private static function announce(array $attributes): void
    {
        Announcement::create($attributes);

        MerchantPush::notify((int) $attributes['merchant_id'], $attributes['title'], (string) $attributes['body']);
    }
}
