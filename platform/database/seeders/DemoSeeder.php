<?php

namespace Database\Seeders;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\City;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\PriceList;
use App\Models\PriceListRule;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Seeder;

/**
 * بيانات تجريبية: شركتان متنافستان تحت النواة نفسها.
 * وجود شركتين مقصود — به وحده يظهر أي تسريب بين المستأجرين.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        Tenancy::runAsPlatform(function () {
            User::updateOrCreate(
                ['company_id' => null, 'phone' => '07700000000'],
                [
                    'name' => 'مدير المنصّة', 'email' => 'admin@zajel.iq',
                    'password' => 'password', 'role' => UserRole::PlatformAdmin, 'is_active' => true,
                ],
            );
        });

        $this->company('zajel', 'الزاجل للتوصيل', 'Al-Zajel Delivery', '#0EA5A4', 'enterprise');
        $this->company('barq', 'البرق للتوصيل', 'Al-Barq Delivery', '#7C3AED', 'growth');
    }

    private function company(string $slug, string $name, string $nameEn, string $color, string $planCode): void
    {
        $baghdad = Governorate::where('code', 'BGD')->first();

        $company = Tenancy::runAsPlatform(fn () => Company::updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name, 'name_en' => $nameEn, 'primary_color' => $color,
                'phone' => '0770'.random_int(1000000, 9999999),
                'governorate_id' => $baghdad?->id, 'status' => 'active',
            ],
        ));

        Tenancy::runFor($company, function (Company $company) use ($planCode, $baghdad) {
            $plan = Plan::where('code', $planCode)->first();

            Subscription::firstOrCreate(
                ['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active'],
                [
                    'billing_cycle' => 'monthly', 'price' => $plan->price_monthly,
                    'commission_per_shipment' => $plan->commission_per_shipment,
                    'starts_at' => now()->startOfMonth(), 'ends_at' => now()->addMonth()->endOfMonth(),
                ],
            );

            $branch = Branch::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'BGD'],
                [
                    'name' => 'فرع بغداد', 'governorate_id' => $baghdad?->id,
                    'is_main' => true, 'is_active' => true, 'phone' => '07701234567',
                ],
            );

            Hub::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'HUB-BGD'],
                [
                    'name' => 'مركز فرز بغداد', 'type' => 'main',
                    'branch_id' => $branch->id, 'governorate_id' => $baghdad?->id,
                ],
            );

            $owner = User::updateOrCreate(
                ['company_id' => $company->id, 'phone' => '0770'.substr(md5($company->slug), 0, 7)],
                [
                    'name' => 'صاحب '.$company->name, 'email' => $company->slug.'@zajel.iq',
                    'password' => 'password', 'role' => UserRole::CompanyOwner,
                    'branch_id' => $branch->id, 'is_active' => true,
                ],
            );

            $priceList = $this->priceList($company->id);
            $merchants = $this->merchants($company, $branch, $priceList);
            $couriers  = $this->couriers($company, $branch);

            $this->shipments($merchants, $couriers, $owner);
        });
    }

    private function priceList(int $companyId): PriceList
    {
        $list = PriceList::updateOrCreate(
            ['company_id' => $companyId, 'name' => 'التسعيرة الافتراضية'],
            ['is_default' => true, 'is_active' => true],
        );

        $baghdad = Governorate::where('code', 'BGD')->first();

        // قاعدة عامة: أي محافظة
        PriceListRule::updateOrCreate(
            ['price_list_id' => $list->id, 'to_governorate_id' => null, 'to_city_id' => null,
             'weight_from_grams' => 0, 'weight_to_grams' => 5000],
            ['delivery_fee' => 5000, 'return_fee' => 2500, 'replacement_fee' => 3000,
             'extra_kg_fee' => 1000, 'priority' => 0, 'is_active' => true],
        );

        // داخل بغداد أرخص
        PriceListRule::updateOrCreate(
            ['price_list_id' => $list->id, 'to_governorate_id' => $baghdad?->id, 'to_city_id' => null,
             'weight_from_grams' => 0, 'weight_to_grams' => 5000],
            ['delivery_fee' => 4000, 'return_fee' => 2000, 'replacement_fee' => 2500,
             'extra_kg_fee' => 1000, 'priority' => 0, 'is_active' => true],
        );

        return $list;
    }

    private function merchants(Company $company, Branch $branch, PriceList $list): array
    {
        $names = ['متجر النور', 'بوتيك ليان', 'إلكترونيات الرافدين', 'عطور بغداد', 'مكتبة المعرفة'];
        $baghdad = Governorate::where('code', 'BGD')->first();
        $out = [];

        foreach ($names as $i => $name) {
            $out[] = Merchant::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'M'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT)],
                [
                    'business_name' => $name, 'owner_name' => 'صاحب '.$name,
                    'phone' => '0771'.str_pad((string) (1000000 + $i + crc32($company->slug) % 1000), 7, '0', STR_PAD_LEFT),
                    'branch_id' => $branch->id, 'governorate_id' => $baghdad?->id,
                    'address' => 'بغداد - الكرادة', 'landmark' => 'قرب مول بابل',
                    'price_list_id' => $list->id, 'status' => 'active', 'settlement_cycle' => 'weekly',
                ],
            );
        }

        return $out;
    }

    private function couriers(Company $company, Branch $branch): array
    {
        $specs = [
            ['أحمد الساعدي', 'delivery'], ['حيدر الجبوري', 'delivery'],
            ['مصطفى الخفاجي', 'delivery'], ['علي الدليمي', 'pickup'],
        ];
        $out = [];

        foreach ($specs as $i => [$name, $type]) {
            $code = 'C'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
            $phone = '0772'.str_pad((string) (2000000 + $i + crc32($company->slug) % 1000), 7, '0', STR_PAD_LEFT);

            $user = User::updateOrCreate(
                ['company_id' => $company->id, 'phone' => $phone],
                [
                    'name' => $name, 'password' => 'password', 'role' => UserRole::Courier,
                    'branch_id' => $branch->id, 'is_active' => true,
                ],
            );

            $courier = Courier::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name, 'phone' => $phone, 'user_id' => $user->id,
                    'branch_id' => $branch->id, 'type' => $type, 'status' => 'active',
                    'commission_per_delivery' => 1500, 'commission_per_pickup' => 500,
                    'cash_limit' => 3_000_000,
                ],
            );

            $user->update(['courier_id' => $courier->id]);
            $out[] = $courier;
        }

        return $out;
    }

    private function shipments(array $merchants, array $couriers, User $actor): void
    {
        $create = app(CreateShipment::class);
        $change = app(ChangeShipmentStatus::class);

        $recipients = [
            ['علي حسين', '07801234567', 'BGD', 'الكرادة',     'مقابل جامع الشيخ معروف'],
            ['زينب كاظم', '07709876543', 'BGD', 'المنصور',     'قرب سوق المنصور'],
            ['محمد جاسم', '07715554433', 'BSR', 'مركز البصرة', 'خلف مستشفى الموانئ'],
            ['نور الهدى', '07822223344', 'NJF', 'مركز النجف',  'قرب مرآب النجف'],
            ['كرار عباس', '07731112233', 'ERB', 'عنكاوا',      'قرب كنيسة مار يوسف'],
            ['سجى مهدي', '07801119988', 'BBL', 'الحلة',        'شارع 40 قرب الصيدلية'],
            ['حسن فاضل', '07744445566', 'DHQ', 'الناصرية',     'قرب جسر الزيتون'],
            ['رغد سالم', '07866667788', 'BGD', 'الدورة',       'قرب مجمع الدورة'],
        ];

        $deliverers = array_values(array_filter($couriers, fn (Courier $c) => $c->type !== 'pickup'));

        foreach ($recipients as $i => [$name, $phone, $govCode, $cityName, $landmark]) {
            $gov = Governorate::where('code', $govCode)->first();
            $city = City::where('governorate_id', $gov->id)->where('name_ar', $cityName)->first();
            $merchant = $merchants[$i % count($merchants)];

            $shipment = $create->handle([
                'merchant_id'     => $merchant->id,
                'recipient_name'  => $name,
                'recipient_phone' => $phone,
                'governorate_id'  => $gov->id,
                'city_id'         => $city?->id,
                'address'         => $gov->name_ar.' - '.$cityName,
                'landmark'        => $landmark,
                'description'     => 'طلب تجريبي رقم '.($i + 1),
                'pieces_count'    => 1,
                'weight_grams'    => random_int(300, 4000),
                'cod_amount'      => random_int(10, 120) * 1000,
                'source'          => 'web',
            ], $actor);

            // توزيع الحالات ليظهر النظام كما هو في يوم عمل حقيقي
            $path = match ($i % 4) {
                0 => [ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered],
                1 => [ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery],
                2 => [ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::InTransit],
                default => [ShipmentStatus::PendingPickup],
            };

            foreach ($path as $status) {
                $options = [];

                if ($status === ShipmentStatus::OutForDelivery) {
                    $options['courier_id'] = $deliverers[$i % count($deliverers)]->id;
                }

                $change->handle($shipment, $status, $actor, $options);
                $shipment->refresh();
            }
        }
    }
}
