<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Merchant\CreateShipmentController as MerchantCreateShipmentController;
use App\Http\Controllers\Api\Merchant\HomeController as MerchantHomeController;
use App\Http\Controllers\Api\Merchant\ShipmentController as MerchantShipmentController;
use App\Http\Controllers\Tenant\AppAdController;
use App\Http\Middleware\IdentifyTenant;
use Illuminate\Support\Facades\Route;

/*
| واجهة التطبيقات (docs/plan/48): تطبيق التاجر ثم المندوب.
|
| الشركة من النطاق كالموقع (zajel.wahaj.iq/api/v1/…)، فعنوان النظام في ملف إعداد كل
| تطبيقٍ هو كلّ ما يفرّق تطبيق الزاجل عن تطبيق البرق. والدخول برمزٍ يحمله التطبيق
| (Sanctum)، لا بجلسةٍ وكعكة: لا نموذج CSRF ولا انتهاء جلسةٍ على الهاتف.
*/
Route::prefix('v1')->name('api.')->middleware(IdentifyTenant::class)->group(function () {
    Route::get('/company', [AuthController::class, 'company'])->name('company');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('login');

    Route::middleware(['auth:sanctum', 'api-user', 'throttle:120,1'])->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/app-ads/{ad}/image', [AppAdController::class, 'image'])->whereNumber('ad')
            ->middleware('feature:app_ads')->name('app-ads.image');

        Route::prefix('merchant')->name('merchant.')->middleware(['abilities:merchant', 'merchant'])->group(function () {
            Route::get('/home', MerchantHomeController::class)->name('home');
            Route::get('/shipments', [MerchantShipmentController::class, 'index'])->name('shipments.index');
            Route::get('/shipments/{shipment}', [MerchantShipmentController::class, 'show'])->whereNumber('shipment')->name('shipments.show');
            // «طلب جديد» (docs/plan/52): نموذج البوابة نفسه
            Route::get('/shipments/form', [MerchantCreateShipmentController::class, 'form'])->name('shipments.form');
            Route::get('/areas', [MerchantCreateShipmentController::class, 'areas'])->name('areas');
            Route::get('/shipments/quote', [MerchantCreateShipmentController::class, 'quote'])->name('shipments.quote');
            Route::get('/waybills/check', [MerchantCreateShipmentController::class, 'waybill'])
                ->middleware(['feature:waybills', 'throttle:60,1'])->name('waybills.check');
            Route::post('/shipments', [MerchantCreateShipmentController::class, 'store'])->middleware('throttle:60,1')->name('shipments.store');
        });
    });
});
