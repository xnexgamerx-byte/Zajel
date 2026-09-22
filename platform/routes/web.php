<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Platform\CompanyController as PlatformCompanyController;
use App\Http\Controllers\Courier\ActionController as CourierActionController;
use App\Http\Controllers\Courier\CashController as CourierCashController;
use App\Http\Controllers\Courier\PickupController as CourierPickupController;
use App\Http\Controllers\Courier\ShareController as CourierShareController;
use App\Http\Controllers\Courier\TaskController;
use App\Http\Controllers\Portal\DashboardController as PortalDashboardController;
use App\Http\Controllers\Portal\PickupRequestController;
use App\Http\Controllers\Portal\ShipmentController as PortalShipmentController;
use App\Http\Controllers\Portal\ShipmentImportController as PortalShipmentImportController;
use App\Http\Controllers\Portal\StatementController;
use App\Http\Controllers\Platform\DashboardController as PlatformDashboardController;
use App\Http\Controllers\Platform\ImpersonationController;
use App\Http\Controllers\Platform\InvoiceController;
use App\Http\Controllers\Platform\LoginController as PlatformLoginController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Tenant\BranchAccountController;
use App\Http\Controllers\Tenant\BranchController;
use App\Http\Controllers\Tenant\BagController;
use App\Http\Controllers\Tenant\CashBoxController;
use App\Http\Controllers\Tenant\ControlController;
use App\Http\Controllers\Tenant\CourierController;
use App\Http\Controllers\Tenant\CourierManifestController;
use App\Http\Controllers\Tenant\DashboardController as TenantDashboardController;
use App\Http\Controllers\Tenant\CourierSettlementController;
use App\Http\Controllers\Tenant\MerchantSettlementController;
use App\Http\Controllers\Tenant\ExpenseController;
use App\Http\Controllers\Tenant\ManifestController;
use App\Http\Controllers\Tenant\MerchantController;
use App\Http\Controllers\Tenant\PickupRequestController as TenantPickupRequestController;
use App\Http\Controllers\Tenant\PermissionController;
use App\Http\Controllers\Tenant\PickupAgentController;
use App\Http\Controllers\Tenant\PriceListController;
use App\Http\Controllers\Tenant\ReportController;
use App\Http\Controllers\Tenant\ReturnController;
use App\Http\Controllers\Tenant\PricingQuoteController;
use App\Http\Controllers\Tenant\ShipmentAmountController;
use App\Http\Controllers\Tenant\ShipmentController;
use App\Http\Controllers\Tenant\ShipmentImportController;
use App\Http\Controllers\Tenant\ShipmentStatusController;
use App\Http\Controllers\Tenant\UserController;
use App\Http\Controllers\Tenant\ZoneController;
use Illuminate\Support\Facades\Route;

/*
| كل مسار هنا يمرّ بـ tenant: تُحدَّد الشركة من النطاق الفرعي أولاً،
| ثم يُفلتَر كل استعلام بـ company_id تلقائياً. لا يوجد مسار "عام"
| يقرأ بيانات شركة بلا هذه الخطوة.
*/

Route::middleware('tenant')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->middleware('guest')->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('guest');
    Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

    Route::middleware('auth')->group(function () {
        Route::get('/', TenantDashboardController::class)->middleware('staff')->name('dashboard');

        Route::get('/shipments', [ShipmentController::class, 'index'])->name('shipments.index');
        Route::get('/shipments/create', [ShipmentController::class, 'create'])
            ->middleware(['staff', 'can:shipments.create'])->name('shipments.create');
        Route::post('/shipments', [ShipmentController::class, 'store'])
            ->middleware(['staff', 'can:shipments.create'])->name('shipments.store');

        Route::middleware(['staff', 'can:shipments.create'])->group(function () {
            Route::get('/shipments/import', [ShipmentImportController::class, 'create'])->name('shipments.import');
            Route::get('/shipments/import/template', [ShipmentImportController::class, 'template'])->name('shipments.import.template');
            Route::post('/shipments/import', [ShipmentImportController::class, 'store'])->name('shipments.import.store');
            Route::post('/shipments/import/confirm', [ShipmentImportController::class, 'confirm'])->name('shipments.import.confirm');
        });
        Route::get('/shipments/{shipment}', [ShipmentController::class, 'show'])->name('shipments.show');
        Route::post('/shipments/{shipment}/status', [ShipmentStatusController::class, 'update'])
            ->middleware(['staff', 'can:shipments.status'])->name('shipments.status');
        Route::post('/shipments/assign', [ShipmentStatusController::class, 'assign'])
            ->middleware(['staff', 'can:shipments.assign'])->name('shipments.assign');
        Route::post('/shipments/{shipment}/amount', [ShipmentAmountController::class, 'update'])
            ->middleware(['staff', 'can:money.confirm_amount'])->name('shipments.amount');

        Route::middleware('staff')->group(function () {
            // الراجع خطوتان: من المندوب إلى المخزن، ومن المخزن إلى التاجر
            Route::middleware('can:returns.manage')->group(function () {
                Route::get('/returns', [ReturnController::class, 'incoming'])->name('returns.incoming');
                Route::post('/returns/receive', [ReturnController::class, 'receive'])->name('returns.receive');
                Route::get('/returns/handover', [ReturnController::class, 'outgoing'])->name('returns.outgoing');
                Route::post('/returns/handover', [ReturnController::class, 'deliver'])->name('returns.deliver');
            });

            Route::middleware('can:settings.permissions')->group(function () {
                Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');
                Route::post('/permissions/{user}', [PermissionController::class, 'update'])->name('permissions.update');
            });

            Route::middleware('can:settings.zones')->group(function () {
                Route::get('/zones', [ZoneController::class, 'index'])->name('zones.index');
                Route::post('/zones', [ZoneController::class, 'store'])->name('zones.store');
                Route::delete('/zones/{zone}', [ZoneController::class, 'destroy'])->name('zones.destroy');
            });

            // الرقابة: قوائم تُحسَم لا تقارير تُقرأ
            Route::middleware('can:control.duplicates')->group(function () {
                Route::get('/control/duplicates', [ControlController::class, 'duplicates'])->name('control.duplicates');
                Route::post('/control/duplicates/{shipment}/clear', [ControlController::class, 'clearDuplicate'])->name('control.duplicates.clear');
                Route::post('/control/duplicates/{shipment}/cancel', [ControlController::class, 'cancelDuplicate'])->name('control.duplicates.cancel');
            });
            Route::get('/control/forced', [ControlController::class, 'forced'])
                ->middleware('can:control.force')->name('control.forced');

            // مندوب الاستلام: دور محاسبيّ مستقلّ عن مندوب التوصيل
            Route::middleware('can:money.view')->group(function () {
                Route::get('/pickup-agents', [PickupAgentController::class, 'index'])->name('pickup-agents.index');
                Route::get('/pickup-agents/objections', [PickupAgentController::class, 'objections'])->name('pickup-agents.objections');
                Route::get('/pickup-agents/{courier}', [PickupAgentController::class, 'show'])->name('pickup-agents.show');
            });
            Route::middleware('can:money.settle')->group(function () {
                Route::post('/pickup-agents/objections/{share}', [PickupAgentController::class, 'resolve'])->name('pickup-agents.resolve');
                Route::post('/pickup-agents/{courier}/pay', [PickupAgentController::class, 'pay'])->name('pickup-agents.pay');
            });

            // تسعة تقارير لا واحد وثلاثون
            Route::middleware('can:reports.view')->group(function () {
            Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('/reports/returns', [ReportController::class, 'returns'])->name('reports.returns');
            Route::get('/reports/couriers', [ReportController::class, 'couriers'])->name('reports.couriers');
            Route::get('/reports/merchants', [ReportController::class, 'merchants'])->name('reports.merchants');
            Route::get('/reports/governorates', [ReportController::class, 'governorates'])->name('reports.governorates');
            Route::get('/reports/daily', [ReportController::class, 'daily'])->name('reports.daily');
            Route::get('/reports/profit', [ReportController::class, 'profit'])->name('reports.profit');
            Route::get('/reports/dormant', [ReportController::class, 'dormant'])->name('reports.dormant');
            Route::get('/reports/debtors', [ReportController::class, 'debtors'])->name('reports.debtors');
            Route::get('/reports/changes', [ReportController::class, 'changes'])->name('reports.changes');
            });

            // النقل بين المراكز: كيس مختوم على كشف، والوارد يُستلَم كيساً كيساً
            Route::middleware('can:transport.manage')->group(function () {
            Route::get('/bags', [BagController::class, 'index'])->name('bags.index');
            Route::post('/bags', [BagController::class, 'store'])->name('bags.store');
            Route::get('/bags/{bag}', [BagController::class, 'show'])->name('bags.show');
            Route::post('/bags/{bag}/add', [BagController::class, 'add'])->name('bags.add');
            Route::delete('/bags/{bag}/shipments/{shipment}', [BagController::class, 'remove'])->name('bags.remove');
            Route::post('/bags/{bag}/seal', [BagController::class, 'seal'])->name('bags.seal');
            Route::post('/bags/{bag}/open', [BagController::class, 'open'])->name('bags.open');

            // كشف عهدة المندوب — ورقة تخرج معه وتُطابَق عند عودته
            Route::get('/courier-manifests', [CourierManifestController::class, 'index'])->name('courier-manifests.index');
            Route::get('/courier-manifests/{courier}', [CourierManifestController::class, 'show'])->name('courier-manifests.show');

            Route::get('/manifests', [ManifestController::class, 'index'])->name('manifests.index');
            Route::post('/manifests', [ManifestController::class, 'store'])->name('manifests.store');
            Route::get('/manifests/inbound', [ManifestController::class, 'inbound'])->name('manifests.inbound');
            Route::get('/manifests/{manifest}', [ManifestController::class, 'show'])->name('manifests.show');
            Route::post('/manifests/{manifest}/load', [ManifestController::class, 'load'])->name('manifests.load');
            Route::delete('/manifests/{manifest}/bags/{bag}', [ManifestController::class, 'unload'])->name('manifests.unload');
            Route::post('/manifests/{manifest}/dispatch', [ManifestController::class, 'dispatchManifest'])->name('manifests.dispatch');
            Route::post('/manifests/{manifest}/receive', [ManifestController::class, 'receive'])->name('manifests.receive');
            });

            // القاصة والمصروفات: كم في الدرج، وأين ذهب
            Route::middleware('can:money.cash')->group(function () {
                Route::get('/cash', [CashBoxController::class, 'index'])->name('cash.index');
                Route::post('/cash', [CashBoxController::class, 'store'])->name('cash.store');
                Route::post('/cash/transfer', [CashBoxController::class, 'transfer'])->name('cash.transfer');
                Route::post('/cash/{box}/adjust', [CashBoxController::class, 'adjust'])->name('cash.adjust');
            });

            // محاسبة الفروع وتأميناتها
            Route::middleware('can:money.view')->group(function () {
                Route::get('/branch-accounts', [BranchAccountController::class, 'index'])->name('branch-accounts.index');
                Route::get('/branch-accounts/deposits', [BranchAccountController::class, 'deposits'])->name('branch-accounts.deposits');
            });
            Route::post('/branch-accounts/deposits', [BranchAccountController::class, 'storeDeposit'])
                ->middleware('can:money.cash')->name('branch-accounts.deposits.store');

            Route::middleware('can:money.expenses')->group(function () {
                Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
                Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
                Route::post('/expenses/{expense}/pay', [ExpenseController::class, 'pay'])->name('expenses.pay');
                Route::post('/expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])->name('expenses.cancel');
            });

            Route::middleware('can:pickups.manage')->group(function () {
                Route::get('/pickups', [TenantPickupRequestController::class, 'index'])->name('pickups.index');
                Route::post('/pickups/{pickup}/assign', [TenantPickupRequestController::class, 'assign'])->name('pickups.assign');
                Route::post('/pickups/{pickup}/cancel', [TenantPickupRequestController::class, 'cancel'])->name('pickups.cancel');
            });
        });

        Route::get('/couriers/cash', [ShipmentStatusController::class, 'cashBoard'])
            ->middleware('staff')->name('couriers.cash');

        // إدارة التجّار والمندوبين والنقد: لموظّفي الشركة فقط.
        Route::middleware('staff')->group(function () {
            Route::resource('merchants', MerchantController::class)->except(['destroy'])->middleware('can:settings.people');
            Route::resource('couriers', CourierController::class)->except(['destroy'])->middleware('can:settings.people');
            Route::resource('users', UserController::class)->except(['destroy', 'show'])
                ->middleware('can:settings.people');
            Route::resource('branches', BranchController::class)->except(['destroy', 'show'])
                ->middleware('can:settings.branches');

            Route::middleware('can:settings.pricing')->group(function () {
                Route::get('/pricing', [PriceListController::class, 'index'])->name('pricing.index');
                Route::post('/pricing', [PriceListController::class, 'store'])->name('pricing.store');
                Route::get('/pricing/{pricing}', [PriceListController::class, 'edit'])->name('pricing.edit');
                Route::put('/pricing/{pricing}', [PriceListController::class, 'update'])->name('pricing.update');
            });

            Route::prefix('settlements')->name('settlements.')->group(function () {
                Route::get('couriers', [CourierSettlementController::class, 'index'])
                    ->middleware('can:money.view')->name('couriers.index');
                Route::post('couriers', [CourierSettlementController::class, 'store'])
                    ->middleware('can:money.settle')->name('couriers.store');
                Route::get('couriers/{settlement}', [CourierSettlementController::class, 'show'])
                    ->middleware('can:money.view')->name('couriers.show');
                Route::post('couriers/{settlement}/confirm', [CourierSettlementController::class, 'confirm'])
                    ->middleware('can:money.settle')->name('couriers.confirm');

                // كشف التاجر: بناؤه وإقفاله تسوية، ودفعه صلاحية أخرى
                Route::middleware('can:money.view')->group(function () {
                    Route::get('merchants', [MerchantSettlementController::class, 'index'])->name('merchants.index');
                    Route::get('merchants/{settlement}', [MerchantSettlementController::class, 'show'])->name('merchants.show');
                });
                Route::middleware('can:money.settle')->group(function () {
                    Route::post('merchants', [MerchantSettlementController::class, 'store'])->name('merchants.store');
                    Route::post('merchants/{settlement}/confirm', [MerchantSettlementController::class, 'confirm'])->name('merchants.confirm');
                });
                Route::post('merchants/{settlement}/pay', [MerchantSettlementController::class, 'pay'])
                    ->middleware('can:money.pay')->name('merchants.pay');
            });
        });

        Route::post('/quote', PricingQuoteController::class)->name('pricing.quote');

        /*
        | شاشة المندوب: مصمَّمة للجوال، وأربعة إجراءات لا أكثر.
        */
        Route::prefix('courier')->name('courier.')->middleware('courier')->group(function () {
            // حقّ الاعتراض لا معنى له إن لم يكن في يد صاحبه
            Route::get('/shares', [CourierShareController::class, 'index'])->name('shares');
            Route::post('/shares/{share}/object', [CourierShareController::class, 'object'])->name('shares.object');

            Route::get('/', [TaskController::class, 'index'])->name('tasks');
            Route::get('/search', [TaskController::class, 'search'])->name('search');
            Route::get('/today', [TaskController::class, 'today'])->name('today');
            Route::get('/cash', CourierCashController::class)->name('cash');
            Route::get('/pickups', [CourierPickupController::class, 'index'])->name('pickups');
            Route::post('/pickups/{pickup}/complete', [CourierPickupController::class, 'complete'])->name('pickups.complete');
            Route::get('/shipments/{shipment}', [TaskController::class, 'show'])->name('shipments.show');
            Route::post('/shipments/{shipment}', CourierActionController::class)->name('shipments.act');
        });

        /*
        | بوابة التاجر: نفس النظام ونفس البيانات، بواجهة تخصّه.
        | كل شاشة هنا مقيّدة بـ merchant_id فوق تقييد الشركة.
        */
        Route::prefix('portal')->name('portal.')->middleware('merchant')->group(function () {
            Route::get('/', PortalDashboardController::class)->name('dashboard');

            Route::get('/shipments', [PortalShipmentController::class, 'index'])->name('shipments.index');
            Route::get('/shipments/create', [PortalShipmentController::class, 'create'])->name('shipments.create');
            Route::get('/shipments/import', [PortalShipmentImportController::class, 'create'])->name('shipments.import');
            Route::get('/shipments/import/template', [PortalShipmentImportController::class, 'template'])->name('shipments.import.template');
            Route::post('/shipments/import', [PortalShipmentImportController::class, 'store'])->name('shipments.import.store');
            Route::post('/shipments/import/confirm', [PortalShipmentImportController::class, 'confirm'])->name('shipments.import.confirm');
            Route::post('/shipments', [PortalShipmentController::class, 'store'])->name('shipments.store');
            Route::get('/shipments/{shipment}', [PortalShipmentController::class, 'show'])->name('shipments.show');

            Route::get('/statement', StatementController::class)->name('statement');

            Route::get('/pickups', [PickupRequestController::class, 'index'])->name('pickups.index');
            Route::post('/pickups', [PickupRequestController::class, 'store'])->name('pickups.store');
        });
    });
});

/*
| لوحة النواة. تعمل في وضع المنصّة: لا شركة حالية ولا فلترة company_id،
| وهذا هو التجاوز الصريح الوحيد للعزل — محروس بـ platform-user.
*/

Route::prefix('admin')->name('admin.')->middleware('platform')->group(function () {
    Route::get('/login', [PlatformLoginController::class, 'show'])->middleware('guest')->name('login');
    Route::post('/login', [PlatformLoginController::class, 'store'])->middleware('guest');
    Route::post('/logout', [PlatformLoginController::class, 'destroy'])->middleware('auth')->name('logout');

    Route::middleware(['auth', 'platform-user'])->group(function () {
        Route::get('/', PlatformDashboardController::class)->name('dashboard');

        Route::get('/companies', [PlatformCompanyController::class, 'index'])->name('companies.index');
        Route::get('/companies/create', [PlatformCompanyController::class, 'create'])->name('companies.create');
        Route::post('/companies', [PlatformCompanyController::class, 'store'])->name('companies.store');
        Route::get('/companies/{company}', [PlatformCompanyController::class, 'show'])->name('companies.show');
        Route::post('/companies/{company}/suspend', [PlatformCompanyController::class, 'suspend'])->name('companies.suspend');
        Route::post('/companies/{company}/activate', [PlatformCompanyController::class, 'activate'])->name('companies.activate');
        Route::post('/companies/{company}/impersonate', [ImpersonationController::class, 'start'])->name('companies.impersonate');

        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::post('/invoices/generate', [InvoiceController::class, 'generate'])->name('invoices.generate');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::post('/invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->name('invoices.pay');

        Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
        Route::get('/plans/create', [PlanController::class, 'create'])->name('plans.create');
        Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
        Route::get('/plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
        Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
    });
});

// إنهاء الانتحال يتم من داخل نظام الشركة، فهو في مجموعة المستأجر
Route::middleware(['tenant', 'auth'])
    ->post('/stop-impersonating', [ImpersonationController::class, 'stop'])
    ->name('impersonation.stop');
