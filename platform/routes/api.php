<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CallCenter\ConversationController as CallCenterConversationController;
use App\Http\Controllers\Api\CallCenter\HomeController as CallCenterHomeController;
use App\Http\Controllers\Api\CallCenter\ProcessingController as CallCenterProcessingController;
use App\Http\Controllers\Api\CallCenter\ShipmentController as CallCenterShipmentController;
use App\Http\Controllers\Api\CallCenter\TicketController as CallCenterTicketController;
use App\Http\Controllers\Api\Courier\HomeController as CourierHomeController;
use App\Http\Controllers\Api\Courier\InboxController as CourierInboxController;
use App\Http\Controllers\Api\Courier\ShipmentController as CourierShipmentController;
use App\Http\Controllers\Api\Courier\TaskController as CourierTaskController;
use App\Http\Controllers\Api\Courier\WorkController as CourierWorkController;
use App\Http\Controllers\Api\Merchant\CreateShipmentController as MerchantCreateShipmentController;
use App\Http\Controllers\Api\Merchant\FinanceController as MerchantFinanceController;
use App\Http\Controllers\Api\Merchant\HomeController as MerchantHomeController;
use App\Http\Controllers\Api\Merchant\ImportController as MerchantImportController;
use App\Http\Controllers\Api\Merchant\LabelController as MerchantLabelController;
use App\Http\Controllers\Api\Merchant\PickupController as MerchantPickupController;
use App\Http\Controllers\Api\Merchant\ProcessingController as MerchantProcessingController;
use App\Http\Controllers\Api\Merchant\ProfileController as MerchantProfileController;
use App\Http\Controllers\Api\Merchant\ReceiveController as MerchantReceiveController;
use App\Http\Controllers\Api\Merchant\RequestController as MerchantRequestController;
use App\Http\Controllers\Api\Merchant\ShipmentController as MerchantShipmentController;
use App\Http\Controllers\Api\Merchant\SupportController as MerchantSupportController;
use App\Http\Controllers\Api\Merchant\WaybillController as MerchantWaybillController;
use App\Http\Controllers\Api\Partner\PartnerApiController;
use App\Http\Controllers\Courier\LocationController as CourierLocationController;
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

    // واجهة الشركة الوسيطة (docs/plan/64): مندوبٌ عندنا يحدّث شحناته من نظامه بمفتاح ربط
    Route::prefix('partner')->name('partner.')->middleware('partner.key')->group(function () {
        Route::get('/me', [PartnerApiController::class, 'me'])->name('me');
        Route::middleware('partner.can:shipments.read')->group(function () {
            Route::get('/reasons', [PartnerApiController::class, 'reasons'])->name('reasons');
            Route::get('/shipments', [PartnerApiController::class, 'index'])->name('shipments.index');
            Route::get('/shipments/{number}', [PartnerApiController::class, 'show'])->name('shipments.show');
        });
        Route::middleware('partner.can:shipments.status')->group(function () {
            Route::post('/shipments/{number}/status', [PartnerApiController::class, 'status'])->name('shipments.status');
            Route::post('/shipments/{number}/track', [PartnerApiController::class, 'track'])->name('shipments.track');
        });
    });

    Route::middleware(['auth:sanctum', 'api-user', 'throttle:120,1'])->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/app-ads/{ad}/image', [AppAdController::class, 'image'])->whereNumber('ad')
            ->middleware('feature:app_ads')->name('app-ads.image');

        // تطبيق المندوب (docs/plan/66): شاشات المندوب في الموقع نفسها، بالقواعد نفسها (RecordCourierOutcome)
        Route::prefix('courier')->name('courier.')->middleware(['abilities:courier', 'courier'])->group(function () {
            Route::get('/home', [CourierHomeController::class, 'home'])->name('home');
            Route::get('/cash', [CourierHomeController::class, 'cash'])->name('cash');
            Route::get('/tasks', [CourierTaskController::class, 'index'])->name('tasks');
            Route::get('/reasons', [CourierShipmentController::class, 'reasons'])->name('reasons');
            Route::get('/shipments/find', [CourierShipmentController::class, 'find'])->name('shipments.find');
            Route::get('/shipments/{shipment}', [CourierShipmentController::class, 'show'])->whereNumber('shipment')->name('shipments.show');
            Route::post('/shipments/{shipment}/act', [CourierShipmentController::class, 'act'])->whereNumber('shipment')
                ->middleware('throttle:60,1')->name('shipments.act');
            Route::get('/shipments/{shipment}/ticket', [CourierShipmentController::class, 'ticket'])->whereNumber('shipment')->name('shipments.ticket');
            Route::post('/shipments/{shipment}/ticket', [CourierShipmentController::class, 'openTicket'])->whereNumber('shipment')
                ->middleware('throttle:20,1')->name('shipments.ticket.store');
            // موقع الهاتف ما دام التطبيق مفتوحاً (docs/plan/62) — المتحكّم نفسه الذي في الموقع
            Route::post('/location', CourierLocationController::class)->middleware('throttle:30,1')->name('location');

            // «اليوم» و«الاستلام» و«حصصي» والجرس والمحادثة (docs/plan/71): شاشاته في الموقع نفسها
            Route::get('/today', [CourierWorkController::class, 'today'])->name('today');
            Route::get('/pickups', [CourierWorkController::class, 'pickups'])->name('pickups');
            // بمسح الشحنات وحده (docs/plan/72)
            Route::get('/pickups/{pickup}/scan', [CourierWorkController::class, 'scanPickup'])->whereNumber('pickup')
                ->middleware(['feature:scan_parcels', 'throttle:120,1'])->name('pickups.scan');
            Route::post('/pickups/{pickup}/complete', [CourierWorkController::class, 'completePickup'])->whereNumber('pickup')
                ->middleware('throttle:30,1')->name('pickups.complete');
            Route::get('/shares', [CourierWorkController::class, 'shares'])->name('shares');
            Route::post('/shares/{share}/object', [CourierWorkController::class, 'objectShare'])->whereNumber('share')
                ->middleware('throttle:20,1')->name('shares.object');
            Route::post('/payouts/{payout}/confirm', [CourierWorkController::class, 'confirmPayout'])->whereNumber('payout')->name('payouts.confirm');
            Route::get('/notifications', [CourierInboxController::class, 'notifications'])->name('notifications');
            Route::middleware('feature:conversations')->group(function () {
                Route::get('/chat', [CourierInboxController::class, 'chat'])->name('chat');
                Route::post('/chat', [CourierInboxController::class, 'send'])->middleware('throttle:30,1')->name('chat.send');
            });
        });

        // تطبيق الكول سنتر (docs/plan/73): شاشات المتابعة في الموقع نفسها، ومن فتحت الطلب تعالجه وحدها
        Route::prefix('callcenter')->name('callcenter.')->middleware(['abilities:callcenter', 'staff'])->group(function () {
            Route::get('/home', CallCenterHomeController::class)->name('home');

            Route::get('/tickets', [CallCenterTicketController::class, 'index'])->name('tickets.index');
            Route::get('/tickets/{ticket}', [CallCenterTicketController::class, 'show'])->whereNumber('ticket')->name('tickets.show');
            Route::post('/tickets/{ticket}/claim', [CallCenterTicketController::class, 'claim'])->whereNumber('ticket')->name('tickets.claim');
            Route::post('/tickets/{ticket}/release', [CallCenterTicketController::class, 'release'])->whereNumber('ticket')->name('tickets.release');
            Route::post('/tickets/{ticket}/approve', [CallCenterTicketController::class, 'approve'])->whereNumber('ticket')->name('tickets.approve');
            Route::post('/tickets/{ticket}/reject', [CallCenterTicketController::class, 'reject'])->whereNumber('ticket')->name('tickets.reject');

            Route::get('/processing', [CallCenterProcessingController::class, 'index'])->name('processing.index');
            Route::get('/processing/{shipment}', [CallCenterProcessingController::class, 'show'])->whereNumber('shipment')->name('processing.show');
            Route::post('/processing/{shipment}/claim', [CallCenterProcessingController::class, 'claim'])->whereNumber('shipment')->name('processing.claim');
            Route::post('/processing/{shipment}/release', [CallCenterProcessingController::class, 'release'])->whereNumber('shipment')->name('processing.release');
            Route::post('/processing/{shipment}/decide', [CallCenterProcessingController::class, 'decide'])->whereNumber('shipment')->name('processing.decide');
            Route::post('/processing/{shipment}/merchant', [CallCenterProcessingController::class, 'merchant'])->whereNumber('shipment')
                ->middleware('throttle:60,1')->name('processing.merchant');
            Route::post('/processing/{shipment}/courier', [CallCenterProcessingController::class, 'courier'])->whereNumber('shipment')
                ->middleware('throttle:60,1')->name('processing.courier');

            Route::middleware('feature:conversations')->group(function () {
                Route::get('/conversations', [CallCenterConversationController::class, 'index'])->name('conversations.index');
                Route::get('/conversations/{conversation}', [CallCenterConversationController::class, 'show'])->whereNumber('conversation')->name('conversations.show');
                Route::post('/conversations/{conversation}/reply', [CallCenterConversationController::class, 'reply'])->whereNumber('conversation')
                    ->middleware('throttle:60,1')->name('conversations.reply');
                Route::post('/conversations/{conversation}/close', [CallCenterConversationController::class, 'close'])->whereNumber('conversation')->name('conversations.close');
                Route::post('/conversations/{conversation}/release', [CallCenterConversationController::class, 'release'])->whereNumber('conversation')->name('conversations.release');
            });

            Route::get('/shipments', [CallCenterShipmentController::class, 'index'])->name('shipments.index');
            Route::get('/shipments/{shipment}', [CallCenterShipmentController::class, 'show'])->whereNumber('shipment')->name('shipments.show');
        });

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
            // والحدّ يتّسع لدفعة صورٍ كاملة (docs/plan/63): خمسون صورة ومراجعتها في الدقيقة
            Route::middleware(['feature:order_reading', 'throttle:120,1,order-reading'])->group(function () {
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
            Route::get('/finance/statements/{settlement}', [MerchantFinanceController::class, 'statement'])
                ->whereNumber('settlement')->name('finance.statement');
            Route::post('/finance/statements/{settlement}/confirm', [MerchantFinanceController::class, 'confirm'])
                ->whereNumber('settlement')->name('finance.confirm');
            // الأدوات السريعة (docs/plan/56): طلبات الاستلام، وطلباتي، والدعم
            Route::get('/pickups', [MerchantPickupController::class, 'index'])->name('pickups.index');
            Route::post('/pickups', [MerchantPickupController::class, 'store'])->middleware('throttle:20,1')->name('pickups.store');
            Route::get('/requests', [MerchantRequestController::class, 'index'])->name('requests.index');
            Route::post('/requests', [MerchantRequestController::class, 'store'])->middleware('throttle:20,1')->name('requests.store');
            Route::post('/requests/{merchantRequest}/cancel', [MerchantRequestController::class, 'cancel'])->whereNumber('merchantRequest')
                ->name('requests.cancel');
            // طباعة وصولات الطلبات المدخلة (docs/plan/63)
            Route::get('/labels', [MerchantLabelController::class, 'index'])->name('labels.index');
            Route::post('/labels', [MerchantLabelController::class, 'store'])->middleware('throttle:30,1')->name('labels.store');
            Route::get('/returns/{batch}', [MerchantRequestController::class, 'batch'])->whereNumber('batch')->name('returns.show');
            Route::post('/returns/{batch}/confirm', [MerchantRequestController::class, 'confirm'])->whereNumber('batch')->name('returns.confirm');
            // «استلام بالباركود»: كاميرا التطبيق تمسح وصل الحساب أو إيصال الراجع (docs/plan/69)
            Route::post('/receive', MerchantReceiveController::class)->middleware(['feature:scan_receipts', 'throttle:20,1'])->name('receive');
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
