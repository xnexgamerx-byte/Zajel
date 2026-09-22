<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * باقات النواة. الأسعار بالدينار العراقي وهي نقطة بداية للتفاوض،
 * لا رقم نهائي — تُعدَّل من لوحة النواة بلا نشر جديد.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'basic', 'name' => 'الأساسية',
                'price_monthly' => 250_000, 'price_yearly' => 2_500_000,
                'commission_per_shipment' => 0, 'commission_percent' => 0,
                'max_branches' => 3, 'max_users' => 15, 'max_couriers' => 20,
                'max_merchants' => 150, 'max_shipments_per_month' => 10_000,
                'features' => [
                    'pickup_agents' => false, 'hubs' => false, 'api_access' => false,
                    'webhooks' => false, 'branded_apps' => false, 'custom_domain' => false,
                ],
                'sort_order' => 1,
            ],
            [
                'code' => 'growth', 'name' => 'النمو',
                'price_monthly' => 500_000, 'price_yearly' => 5_000_000,
                'commission_per_shipment' => 0, 'commission_percent' => 0,
                'max_branches' => 10, 'max_users' => 60, 'max_couriers' => 80,
                'max_merchants' => 1_000, 'max_shipments_per_month' => 40_000,
                'features' => [
                    'pickup_agents' => true, 'hubs' => true, 'api_access' => true,
                    'webhooks' => true, 'branded_apps' => false, 'custom_domain' => false,
                ],
                'sort_order' => 2,
            ],
            [
                'code' => 'enterprise', 'name' => 'المؤسسات',
                'price_monthly' => 1_000_000, 'price_yearly' => 10_000_000,
                'commission_per_shipment' => 0, 'commission_percent' => 0,
                'max_branches' => null, 'max_users' => null, 'max_couriers' => null,
                'max_merchants' => null, 'max_shipments_per_month' => null,
                'features' => [
                    'pickup_agents' => true, 'hubs' => true, 'api_access' => true,
                    'webhooks' => true, 'branded_apps' => true, 'custom_domain' => true,
                ],
                'sort_order' => 3,
            ],
            [
                'code' => 'commission', 'name' => 'بالعمولة',
                'price_monthly' => 0, 'price_yearly' => 0,
                'commission_per_shipment' => 250, 'commission_percent' => 0,
                'max_branches' => null, 'max_users' => null, 'max_couriers' => null,
                'max_merchants' => null, 'max_shipments_per_month' => null,
                'features' => [
                    'pickup_agents' => true, 'hubs' => true, 'api_access' => true,
                    'webhooks' => true, 'branded_apps' => false, 'custom_domain' => false,
                ],
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], $plan + ['is_active' => true]);
        }
    }
}
