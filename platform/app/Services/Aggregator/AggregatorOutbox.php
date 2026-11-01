<?php

namespace App\Services\Aggregator;

use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Models\AggregatorEvent;
use App\Models\AggregatorJob;
use App\Models\AggregatorLink;
use App\Models\AggregatorPlace;
use App\Models\Company;
use App\Models\Shipment;
use App\Support\Phone;
use Illuminate\Support\Collection;

/**
 * ما يُرسَل لنظام الوسيط (docs/plan/104).
 *
 * - الشحنة التي تُسلَّم للوسيط («بالطريق» ومندوبها المندوب الوسيط المربوط) تُنشأ عندهم برقم وصلنا
 *   مسبوقاً بالبادئة، مرّةً واحدة.
 * - الراجع الذي استلمناه منهم يُبلَّغ «راجعٌ للتاجر» عندهم.
 *
 * لا يُرسَل شيءٌ في طلب الموظّف: يُكتب عملاً في المعاملة نفسها، ويرسله المجدوِل (aggregator:sync)
 * دفعاتٍ، ويعيد ما فشل بمهلةٍ تطول. فلا يبطئ الإسناد بطءُ نظامهم، ولا يضيع طلبٌ إن تعطّل.
 */
class AggregatorOutbox
{
    /** في كل دفعة إنشاء: حدّ نظامهم */
    public const BATCH = 30;

    /** مهلة الإعادة بالدقائق بعد كل فشل */
    private const BACKOFF = [1, 5, 15, 60, 180, 720];

    /** بعد تغيّر حالة الشحنة، داخل معاملتها (ChangeShipmentStatus) */
    public static function afterTransition(Shipment $shipment, ShipmentStatus $to): void
    {
        if ($to !== ShipmentStatus::InTransit || filled($shipment->aggregator_order_id)) {
            return;
        }

        $link = self::linkFor($shipment);

        if ($link?->sends_orders) {
            self::enqueue($shipment, 'create');
        }
    }

    /** بعد استلام راجعها من المندوب الوسيط (ReceiveReturns) */
    public static function afterReturnReceived(Shipment $shipment): void
    {
        $link = filled($shipment->aggregator_order_id) ? self::linkFor($shipment) : null;

        // قالوا هم إنّهم سلّموها لنا (حالتهم «راجع للتاجر» مثلاً): هي عندهم كذلك، فلا يُعاد إبلاغهم
        if ($link === null || self::theySaidReturned($link, $shipment)) {
            return;
        }

        self::enqueue($shipment, 'receive_return');
    }

    private static function theySaidReturned(AggregatorLink $link, Shipment $shipment): bool
    {
        return AggregatorEvent::query()->withoutGlobalScopes()->where('shipment_id', $shipment->id)
            ->pluck('status_id')
            ->contains(fn ($id) => in_array($link->actionFor($id), AggregatorLink::RETURNED_TO_US, true));
    }

    /** ربط شركة الشحنة إن كانت الميزة مفتوحةً لها ومندوبها هو الوسيط المربوط */
    public static function linkFor(Shipment $shipment): ?AggregatorLink
    {
        $company = Company::query()->find($shipment->company_id);

        if (! $company?->hasFeature(Feature::Aggregator) || ! $shipment->delivery_courier_id) {
            return null;
        }

        $link = AggregatorLink::query()->withoutGlobalScopes()->where('company_id', $shipment->company_id)->first();

        return $link && (int) $link->courier_id === (int) $shipment->delivery_courier_id ? $link : null;
    }

    private static function enqueue(Shipment $shipment, string $action): void
    {
        $open = AggregatorJob::query()->withoutGlobalScopes()
            ->where('shipment_id', $shipment->id)->where('action', $action)->where('status', '!=', 'done')->exists();

        if ($open) {
            return;
        }

        $job = new AggregatorJob([
            'shipment_id' => $shipment->id,
            'action' => $action,
            'status' => 'pending',
            'next_attempt_at' => now(),
        ]);
        $job->company_id = $shipment->company_id;
        $job->save();
    }

    /**
     * يرسل ما حان من أعمال الشركة. داخل Tenancy::runFor($company).
     *
     * @return array{sent: int, failed: int}
     */
    public function run(AggregatorLink $link, int $limit = 300): array
    {
        $due = AggregatorJob::query()->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->with('shipment')->orderBy('id')->limit($limit)->get();

        $counts = ['sent' => 0, 'failed' => 0];
        $client = AggregatorClient::for($link);

        foreach ($due->where('action', 'receive_return') as $job) {
            $this->attempt($job, $counts, function () use ($client, $job) {
                $client->receiveReturned((string) $job->shipment->aggregator_order_id);
            });
        }

        $creates = $due->where('action', 'create');

        // ما أُنشئ قبلُ أو لم يعد للوسيط: يُغلق بلا طلب
        foreach ($creates as $key => $job) {
            if (filled($job->shipment?->aggregator_order_id)) {
                $this->done($job, $counts);
                $creates->forget($key);
            } elseif ((int) $job->shipment?->delivery_courier_id !== (int) $link->courier_id) {
                $this->fail($job, $counts, 'لم تعد الشحنة مع الشركة الوسيطة.', final: true);
                $creates->forget($key);
            }
        }

        foreach ($creates->chunk(self::BATCH) as $batch) {
            $this->createBatch($link, $client, $batch, $counts);
        }

        return $counts;
    }

    /** يعيد عملاً فشل نهائياً إلى الانتظار: من شاشة الربط */
    public static function retry(AggregatorJob $job): void
    {
        $job->forceFill(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null])->save();
    }

    /**
     * الطلب كما يطلبه نظامهم (create-bulk-orders)، أو خطأٌ بما ينقص.
     *
     * @return array<string, mixed>
     */
    public static function payload(Shipment $shipment, AggregatorLink $link): array
    {
        $phone = Phone::normalise($shipment->recipient_phone);
        if ($phone === null) {
            throw new AggregatorException("هاتف الزبون في الشحنة {$shipment->number} ليس رقماً عراقياً صحيحاً.");
        }
        $alt = Phone::normalise($shipment->recipient_phone_alt);

        $city = self::place('governorate', (int) $shipment->governorate_id);
        if ($city === null) {
            throw new AggregatorException('محافظة «'.($shipment->governorate?->name_ar ?? '—').'» غير مربوطة بمدينةٍ عندهم.');
        }

        $region = $shipment->city_id ? self::place('city', (int) $shipment->city_id) : null;
        if ($region === null) {
            throw new AggregatorException('منطقة «'.($shipment->city?->name_ar ?? 'بلا منطقة').'» غير مربوطة بمنطقةٍ عندهم.');
        }

        $location = collect([$shipment->city?->name_ar, $shipment->address, $shipment->landmark])
            ->filter(fn ($p) => filled($p))->implode(' — ');

        return [
            'client_name' => filled($shipment->recipient_name) ? $shipment->recipient_name : 'زبون',
            'client_mobile' => self::international($phone),
            // مطلوبٌ في الإنشاء الجماعي: الرقم الثاني، وإلّا الأوّل
            'client_mobile2' => self::international($alt ?? $phone),
            'city_id' => (int) $city,
            'region_id' => (int) $region,
            'location' => $location !== '' ? $location : '—',
            'type_name' => filled($shipment->description) ? mb_substr($shipment->description, 0, 100) : 'بضاعة',
            'items_number' => max(1, (int) $shipment->pieces_count),
            // ما يُقبض من الزبون كاملاً، كما على وصلنا
            'price' => (int) $shipment->cod_amount,
            'package_size' => (int) $link->package_size_id,
            'merchant_notes' => (string) ($shipment->notes ?? ''),
            'replacement' => $shipment->type === 'exchange' ? 1 : 0,
            'merchant_id' => (int) $link->merchant_id,
            'company_order_id' => $link->companyOrderId($shipment),
            'metadata' => (string) $shipment->id,
        ];
    }

    /** @param Collection<int, AggregatorJob> $batch */
    private function createBatch(AggregatorLink $link, AggregatorClient $client, Collection $batch, array &$counts): void
    {
        $orders = [];
        $byShipment = [];

        foreach ($batch as $job) {
            $shipment = $job->shipment;

            try {
                // أُرسل قبلُ ولم يصلنا ردّه: يُسأل عنه قبل أن يُنشأ ثانيةً
                if ($job->attempts > 0 && ($found = $client->findOrder($link->companyOrderId($shipment)))) {
                    $this->created($job, $found, $counts);

                    continue;
                }

                $orders[] = self::payload($shipment, $link);
                $byShipment[(string) $shipment->id] = $job;
            } catch (AggregatorException $e) {
                $this->fail($job, $counts, $e->getMessage());
            }
        }

        if ($orders === []) {
            return;
        }

        try {
            $result = $client->createOrders($orders);
        } catch (AggregatorException $e) {
            foreach ($byShipment as $job) {
                $this->fail($job, $counts, $e->getMessage());
            }

            return;
        }

        foreach ($result['succeed'] as $order) {
            if ($job = $this->jobFor($order, $byShipment, $link)) {
                $this->created($job, $order, $counts);
                unset($byShipment[(string) $job->shipment_id]);
            }
        }

        foreach ($result['failed'] as $order) {
            if ($job = $this->jobFor($order, $byShipment, $link)) {
                $this->fail($job, $counts, (string) ($order['failed'] ?? $order['msg'] ?? 'رفضه نظامهم.'));
                unset($byShipment[(string) $job->shipment_id]);
            }
        }

        foreach ($byShipment as $job) {
            $this->fail($job, $counts, 'لم يذكره ردّ نظامهم.');
        }
    }

    /** الطلب في ردّهم بمعرّف شحنتنا (metadata) أو برقمنا عندهم */
    private function jobFor(array $order, array $byShipment, AggregatorLink $link): ?AggregatorJob
    {
        $meta = (string) ($order['metadata'] ?? '');
        if ($meta !== '' && isset($byShipment[$meta])) {
            return $byShipment[$meta];
        }

        $number = $link->localNumber((string) ($order['company_order_id'] ?? ''));

        return collect($byShipment)->first(fn (AggregatorJob $job) => $job->shipment->number === $number);
    }

    private function created(AggregatorJob $job, array $order, array &$counts): void
    {
        $remote = (string) ($order['id'] ?? $order['qr_id'] ?? '');

        if ($remote === '') {
            $this->fail($job, $counts, 'أنشأه نظامهم ولم يُرجع رقمه.');

            return;
        }

        $job->shipment->forceFill(['aggregator_order_id' => $remote])->saveQuietly();
        $this->done($job, $counts);
    }

    private function attempt(AggregatorJob $job, array &$counts, callable $send): void
    {
        try {
            $send();
            $this->done($job, $counts);
        } catch (AggregatorException $e) {
            $this->fail($job, $counts, $e->getMessage());
        }
    }

    private function done(AggregatorJob $job, array &$counts): void
    {
        $job->forceFill(['status' => 'done', 'done_at' => now(), 'last_error' => null, 'attempts' => $job->attempts + 1])->save();
        $counts['sent']++;
    }

    private function fail(AggregatorJob $job, array &$counts, string $error, bool $final = false): void
    {
        $attempts = $job->attempts + 1;
        $final = $final || $attempts >= AggregatorJob::MAX_ATTEMPTS;

        $job->forceFill([
            'status' => $final ? 'failed' : 'pending',
            'attempts' => $attempts,
            'last_error' => mb_substr($error, 0, 1000),
            'next_attempt_at' => $final ? null : now()->addMinutes(self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1]),
        ])->save();
        $counts['failed']++;
    }

    private static function place(string $kind, int $localId): ?string
    {
        return AggregatorPlace::query()->where('kind', $kind)->where('local_id', $localId)->value('remote_id');
    }

    /** 07xxxxxxxxx ← +9647xxxxxxxxx كما يطلبونه */
    private static function international(string $local): string
    {
        return '+964'.substr($local, 1);
    }
}
