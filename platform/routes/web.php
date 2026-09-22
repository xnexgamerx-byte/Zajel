<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Platform\CompanyController as PlatformCompanyController;
use App\Http\Controllers\Platform\DashboardController as PlatformDashboardController;
use App\Http\Controllers\Platform\ImpersonationController;
use App\Http\Controllers\Platform\LoginController as PlatformLoginController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Tenant\CourierController;
use App\Http\Controllers\Tenant\CourierSettlementController;
use App\Http\Controllers\Tenant\MerchantSettlementController;
use App\Http\Controllers\Tenant\MerchantController;
use App\Http\Controllers\Tenant\PricingQuoteController;
use App\Http\Controllers\Tenant\ShipmentController;
use App\Http\Controllers\Tenant\ShipmentStatusController;
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
        Route::redirect('/', '/shipments');

        Route::get('/shipments', [ShipmentController::class, 'index'])->name('shipments.index');
        Route::get('/shipments/create', [ShipmentController::class, 'create'])
            ->middleware('staff')->name('shipments.create');
        Route::post('/shipments', [ShipmentController::class, 'store'])
            ->middleware('staff')->name('shipments.store');
        Route::get('/shipments/{shipment}', [ShipmentController::class, 'show'])->name('shipments.show');
        Route::post('/shipments/{shipment}/status', [ShipmentStatusController::class, 'update'])
            ->middleware('staff')->name('shipments.status');
        Route::post('/shipments/assign', [ShipmentStatusController::class, 'assign'])
            ->middleware('staff')->name('shipments.assign');

        Route::get('/couriers/cash', [ShipmentStatusController::class, 'cashBoard'])
            ->middleware('staff')->name('couriers.cash');

        // إدارة التجّار والمندوبين والنقد: لموظّفي الشركة فقط.
        Route::middleware('staff')->group(function () {
            Route::resource('merchants', MerchantController::class)->except(['destroy']);
            Route::resource('couriers', CourierController::class)->except(['destroy']);

            Route::prefix('settlements')->name('settlements.')->group(function () {
                Route::get('couriers', [CourierSettlementController::class, 'index'])->name('couriers.index');
                Route::post('couriers', [CourierSettlementController::class, 'store'])->name('couriers.store');
                Route::get('couriers/{settlement}', [CourierSettlementController::class, 'show'])->name('couriers.show');
                Route::post('couriers/{settlement}/confirm', [CourierSettlementController::class, 'confirm'])->name('couriers.confirm');

                Route::get('merchants', [MerchantSettlementController::class, 'index'])->name('merchants.index');
                Route::post('merchants', [MerchantSettlementController::class, 'store'])->name('merchants.store');
                Route::get('merchants/{settlement}', [MerchantSettlementController::class, 'show'])->name('merchants.show');
                Route::post('merchants/{settlement}/confirm', [MerchantSettlementController::class, 'confirm'])->name('merchants.confirm');
                Route::post('merchants/{settlement}/pay', [MerchantSettlementController::class, 'pay'])->name('merchants.pay');
            });
        });

        Route::post('/quote', PricingQuoteController::class)->name('pricing.quote');
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
