<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Merchant\CreateShipmentController as MerchantCreateShipmentController;
use App\Http\Controllers\Api\Merchant\FinanceController as MerchantFinanceController;
use App\Http\Controllers\Api\Merchant\HomeController as MerchantHomeController;
use App\Http\Controllers\Api\Merchant\ImportController as MerchantImportController;
use App\Http\Controllers\Api\Merchant\PickupController as MerchantPickupController;
use App\Http\Controllers\Api\Merchant\ProcessingController as MerchantProcessingController;
use App\Http\Controllers\Api\Merchant\ProfileController as MerchantProfileController;
use App\Http\Controllers\Api\Merchant\RequestController as MerchantRequestController;
use App\Http\Controllers\Api\Merchant\ShipmentController as MerchantShipmentController;
use App\Http\Controllers\Api\Merchant\SupportController as MerchantSupportController;
use App\Http\Controllers\Api\Merchant\WaybillController as MerchantWaybillController;
use App\Http\Controllers\OrderListeningController;
use App\Http\Controllers\OrderReadingController;
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
            // صورته أو شعاره، والجرس (docs/plan/59)
            Route::get('/logo', [MerchantProfileController::class, 'logo'])->name('logo.show');
            Route::post('/logo', [MerchantProfileController::class, 'uploadLogo'])->middleware('throttle:20,60')->name('logo.store');
            Route::delete('/logo', [MerchantProfileController::class, 'deleteLogo'])->name('logo.destroy');
            Route::get('/notifications', [MerchantProfileController::class, 'notifications'])->name('notifications');
            Route::get('/shipments', [MerchantShipmentController::class, 'index'])->name('shipments.index');
            Route::get('/shipments/{shipment}', [MerchantShipmentController::class, 'show'])->whereNumber('shipment')->name('shipments.show');
            // «طلب جديد» (docs/plan/52): نموذج البوابة نفسه
            Route::get('/shipments/form', [MerchantCreateShipmentController::class, 'form'])->name('shipments.form');
            Route::get('/areas', [MerchantCreateShipmentController::class, 'areas'])->name('areas');
            Route::get('/shipments/quote', [MerchantCreateShipmentController::class, 'quote'])->name('shipments.quote');
            // بالذكاء الاصطناعي وبالصوت (docs/plan/55): قارئ البوابة نفسه — يملأ النموذج ولا يحفظ
            Route::middleware(['feature:order_reading', 'throttle:30,1,order-reading'])->group(function () {
                Route::post('/shipments/read', OrderReadingController::class)->name('shipments.read');
                Route::post('/shipments/listen', OrderListeningController::class)->name('shipments.listen');
            });
            Route::get('/waybills/check', [MerchantCreateShipmentController::class, 'waybill'])
                ->middleware(['feature:waybills', 'throttle:60,1'])->name('waybills.check');
            // «للمعالجة» و«المالية» (docs/plan/54)
            Route::get('/processing', [MerchantProcessingController::class, 'index'])->name('processing.index');
            Route::post('/processing/{shipment}', [MerchantProcessingController::class, 'store'])->whereNumber('shipment')
                ->middleware('throttle:60,1')->name('processing.store');
            Route::get('/finance', [MerchantFinanceController::class, 'index'])->name('finance');
            Route::post('/finance/request', [MerchantFinanceController::class, 'request'])->middleware('throttle:20,1')->name('finance.request');
            Route::post('/finance/statements/{settlement}/confirm', [MerchantFinanceController::class, 'confirm'])
                ->whereNumber('settlement')->name('finance.confirm');
            // الأدوات السريعة (docs/plan/56): طلبات الاستلام، وطلباتي، والدعم
            Route::get('/pickups', [MerchantPickupController::class, 'index'])->name('pickups.index');
            Route::post('/pickups', [MerchantPickupController::class, 'store'])->middleware('throttle:20,1')->name('pickups.store');
            Route::get('/requests', [MerchantRequestController::class, 'index'])->name('requests.index');
            Route::post('/requests', [MerchantRequestController::class, 'store'])->middleware('throttle:20,1')->name('requests.store');
            Route::post('/requests/{merchantRequest}/cancel', [MerchantRequestController::class, 'cancel'])->whereNumber('merchantRequest')
                ->name('requests.cancel');
            Route::post('/returns/{batch}/confirm', [MerchantRequestController::class, 'confirm'])->whereNumber('batch')->name('returns.confirm');
            Route::middleware('feature:conversations')->prefix('support')->name('support.')->group(function () {
                Route::get('/', [MerchantSupportController::class, 'index'])->name('index');
                Route::post('/', [MerchantSupportController::class, 'store'])->middleware('throttle:20,1')->name('store');
                Route::get('/{conversation}', [MerchantSupportController::class, 'show'])->whereNumber('conversation')->name('show');
                Route::post('/{conversation}/reply', [MerchantSupportController::class, 'reply'])->whereNumber('conversation')
                    ->middleware('throttle:60,1')->name('reply');
                Route::get('/{conversation}/files/{message}', [MerchantSupportController::class, 'file'])
                    ->whereNumber(['conversation', 'message'])->name('file');
            });
            // وصولات للطباعة ورفع شحنات من ملف (docs/plan/57)
            Route::middleware('feature:waybills')->group(function () {
                Route::get('/waybills', [MerchantWaybillController::class, 'index'])->name('waybills.index');
                Route::post('/waybills', [MerchantWaybillController::class, 'store'])->middleware('throttle:20,60')->name('waybills.store');
            });
            Route::middleware('feature:excel_import')->group(function () {
                Route::get('/import', [MerchantImportController::class, 'index'])->name('import.index');
                Route::post('/import', [MerchantImportController::class, 'store'])->middleware('throttle:20,1')->name('import.store');
                Route::post('/import/confirm', [MerchantImportController::class, 'confirm'])->middleware('throttle:20,1')->name('import.confirm');
            });
            Route::post('/shipments', [MerchantCreateShipmentController::class, 'store'])->middleware('throttle:60,1')->name('shipments.store');
        });
    });
});
