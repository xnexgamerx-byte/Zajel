<?php

use App\Http\Controllers\Auth\LoginController;
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
        Route::get('/shipments/create', [ShipmentController::class, 'create'])->name('shipments.create');
        Route::post('/shipments', [ShipmentController::class, 'store'])->name('shipments.store');
        Route::get('/shipments/{shipment}', [ShipmentController::class, 'show'])->name('shipments.show');
        Route::post('/shipments/{shipment}/status', [ShipmentStatusController::class, 'update'])->name('shipments.status');
        Route::post('/shipments/assign', [ShipmentStatusController::class, 'assign'])->name('shipments.assign');

        Route::get('/couriers/cash', [ShipmentStatusController::class, 'cashBoard'])->name('couriers.cash');

        Route::post('/quote', PricingQuoteController::class)->name('pricing.quote');
    });
});
