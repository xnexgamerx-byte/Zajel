<?php

use App\Http\Controllers\ShipmentLabelController;
use App\Http\Controllers\TlsAskController;
use App\Http\Controllers\TrackingController;
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
use App\Http\Controllers\Platform\SubscriptionController as PlatformSubscriptionController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\Portal\SupportController as PortalSupportController;
use App\Http\Controllers\Tenant\AnnouncementController;
use App\Http\Controllers\Tenant\BranchAccountController;
use App\Http\Controllers\Tenant\CompanySettingsController;
use App\Http\Controllers\Tenant\ConversationController;
use App\Http\Controllers\Tenant\ReconcileController;
use App\Http\Controllers\Tenant\PassedThroughController;
use App\Http\Controllers\Tenant\ProcessingController;
use App\Http\Controllers\Tenant\QuickEntryController;
use App\Http\Controllers\Tenant\RankController;
use App\Http\Controllers\Tenant\ReviewHoldController;
use App\Http\Controllers\Tenant\ShipmentExportController;
use App\Http\Controllers\Tenant\ShipmentScanController;
use App\Http\Controllers\Tenant\ShipmentTrashController;
use App\Http\Controllers\Tenant\UserGrantController;
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
use App\Http\Controllers\Tenant\ReferenceReportController;
use App\Http\Controllers\Tenant\FinancialPositionController;
use App\Http\Controllers\Tenant\BranchRemittanceController;
use App\Http\Controllers\Tenant\MyCashBoxController;
use App\Http\Controllers\Tenant\AccountantAccountsController;
use App\Http\Controllers\Tenant\AreaController;
use App\Http\Controllers\Tenant\GovernorateSettingController;
use App\Http\Controllers\Tenant\MerchantRequestController;
use App\Http\Controllers\Tenant\ReturnBatchController;
use App\Http\Controllers\Portal\RequestController as PortalRequestController;
use App\Http\Controllers\Portal\ProcessingController as PortalProcessingController;
use Illuminate\Support\Facades\Route;

/*
| كل مسار هنا يمرّ بـ tenant: تُحدَّد الشركة من النطاق الفرعي أولاً،
| ثم يُفلتَر كل استعلام بـ company_id تلقائياً. لا يوجد مسار "عام"
| يقرأ بيانات شركة بلا هذه الخطوة.
*/

Route::middleware('tenant')->group(function () {
    /*
     | تتبّع الزبون: بلا حساب، وبحدٍّ للمحاولات. رقم الوصل مع آخر أربعة من
     | الهاتف، أو رابط QR من الوصل ببصمته.
     |
     | الحدّ بعنوان IP، وشبكات الهاتف العراقية تُخرج ألوف المشتركين من عنوانٍ
     | واحد. فالحدّ الضيّق على البحث وحده — هو ما يُخمَّن فيه —، وأمّا الرابط
     | فبصمته ٦٤ بتّاً لا تُحزَر، وحدّه للحِمل لا للتخمين.
     */
    Route::get('/track', [TrackingController::class, 'form'])->middleware('throttle:30,1,track')->name('track');
    Route::get('/t/{number}/{token}', [TrackingController::class, 'show'])
        ->middleware('throttle:240,1,track-link')
        ->where(['number' => '[A-Za-z0-9\-]+', 'token' => '[a-f0-9]{16}'])
        ->name('track.show');

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
            Route::get('/shipments/quick', [QuickEntryController::class, 'create'])->name('shipments.quick');
            Route::post('/shipments/quick', [QuickEntryController::class, 'store'])->name('shipments.quick.store');
            Route::get('/shipments/import', [ShipmentImportController::class, 'create'])->name('shipments.import');
            Route::get('/shipments/import/template', [ShipmentImportController::class, 'template'])->name('shipments.import.template');
            Route::post('/shipments/import', [ShipmentImportController::class, 'store'])->name('shipments.import.store');
            Route::post('/shipments/import/confirm', [ShipmentImportController::class, 'confirm'])->name('shipments.import.confirm');
        });
        // قبل /shipments/{shipment}: وإلا قُرئت «labels» رقمَ شحنة
        Route::get('/shipments/labels', [ShipmentLabelController::class, 'staff'])
            ->middleware(['staff', 'can:shipments.view'])->name('shipments.labels');
        Route::get('/shipments/stages', [ShipmentController::class, 'stages'])
            ->middleware(['staff', 'can:shipments.view'])->name('shipments.stages');
        Route::get('/shipments/passed', [PassedThroughController::class, 'index'])
            ->middleware(['staff', 'can:shipments.view'])->name('shipments.passed');
        Route::middleware(['staff', 'can:shipments.view'])->group(function () {
            Route::get('/shipments/scan', [ShipmentScanController::class, 'index'])->name('shipments.scan');
            Route::get('/shipments/scan/lookup', [ShipmentScanController::class, 'lookup'])->name('shipments.scan.lookup');
        });
        Route::post('/shipments/scan/receive', [ShipmentScanController::class, 'receive'])
            ->middleware(['staff', 'can:shipments.status'])->name('shipments.scan.receive');
        Route::middleware(['staff', 'can:shipments.delete'])->group(function () {
            Route::get('/shipments/trash', [ShipmentTrashController::class, 'index'])->name('shipments.trash');
            Route::post('/shipments/trash/{id}/restore', [ShipmentTrashController::class, 'restore'])
                ->whereNumber('id')->name('shipments.trash.restore');
        });
        Route::middleware(['staff', 'can:shipments.export'])->group(function () {
            Route::get('/shipments/export', [ShipmentExportController::class, 'excel'])->name('shipments.export');
            Route::get('/shipments/export/print', [ShipmentExportController::class, 'print'])->name('shipments.export.print');
        });
        Route::get('/shipments/{shipment}', [ShipmentController::class, 'show'])->name('shipments.show');
        Route::middleware(['staff', 'can:shipments.edit'])->group(function () {
            Route::get('/shipments/{shipment}/edit', [ShipmentController::class, 'edit'])->name('shipments.edit');
            Route::put('/shipments/{shipment}', [ShipmentController::class, 'update'])->name('shipments.update');
        });
        Route::delete('/shipments/{shipment}', [ShipmentTrashController::class, 'destroy'])
            ->middleware(['staff', 'can:shipments.delete'])->name('shipments.destroy');
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
                Route::get('/returns/sorting', [ReturnController::class, 'sorting'])->name('returns.sorting');
                Route::post('/returns/sorting', [ReturnController::class, 'sort'])->name('returns.sort');
                Route::get('/returns/handover', [ReturnController::class, 'outgoing'])->name('returns.outgoing');
                Route::post('/returns/handover', [ReturnController::class, 'deliver'])->name('returns.deliver');
                Route::get('/returns/pickup-courier', [ReturnBatchController::class, 'pickup'])->name('returns.pickup');
                Route::post('/returns/pickup-courier', [ReturnBatchController::class, 'handToPickup'])->name('returns.pickup.deliver');
                Route::get('/returns/requests', [MerchantRequestController::class, 'returns'])->name('returns.requests');
                Route::post('/returns/requests/{merchantRequest}/handle', [MerchantRequestController::class, 'handleReturns'])
                    ->whereNumber('merchantRequest')->name('returns.requests.handle');

                // دفعات الراجع: كل تسليمٍ بإيصاله
                Route::get('/return-batches', [ReturnBatchController::class, 'index'])->name('return-batches.index');
                Route::get('/return-batches/print', [ReturnBatchController::class, 'printMany'])->name('return-batches.print-many');
                Route::get('/return-batches/{batch}/print', [ReturnBatchController::class, 'print'])->whereNumber('batch')->name('return-batches.print');
                Route::post('/return-batches/{batch}/confirm', [ReturnBatchController::class, 'confirm'])->whereNumber('batch')->name('return-batches.confirm');
            });

            // طلبات الحساب من بوابات التجّار: تُرى بصلاحية المال، وتُغلق يدوياً بصلاحية التسوية
            Route::get('/merchant-requests/payments', [MerchantRequestController::class, 'payments'])
                ->middleware('can:money.view')->name('merchant-requests.payments');
            Route::post('/merchant-requests/payments/{merchantRequest}/handle', [MerchantRequestController::class, 'handlePayment'])
                ->middleware('can:money.settle')->whereNumber('merchantRequest')->name('merchant-requests.payments.handle');

            // الصلاحيات: من يحمل أيّ مرتبة، والمراتب نفسها، والاستثنائية فوقها
            Route::middleware('can:settings.permissions')->group(function () {
                Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');
                Route::post('/permissions/{user}', [PermissionController::class, 'update'])
                    ->whereNumber('user')->name('permissions.update');

                Route::get('/permissions/ranks', [RankController::class, 'index'])->name('permissions.ranks.index');
                Route::get('/permissions/ranks/create', [RankController::class, 'create'])->name('permissions.ranks.create');
                Route::post('/permissions/ranks', [RankController::class, 'store'])->name('permissions.ranks.store');
                Route::get('/permissions/ranks/{rank}/edit', [RankController::class, 'edit'])->name('permissions.ranks.edit');
                Route::put('/permissions/ranks/{rank}', [RankController::class, 'update'])->name('permissions.ranks.update');
                Route::delete('/permissions/ranks/{rank}', [RankController::class, 'destroy'])->name('permissions.ranks.destroy');

                Route::get('/permissions/exceptions', [UserGrantController::class, 'index'])->name('permissions.grants.index');
                Route::post('/permissions/exceptions', [UserGrantController::class, 'store'])->name('permissions.grants.store');
                Route::delete('/permissions/exceptions/{grant}', [UserGrantController::class, 'destroy'])->name('permissions.grants.destroy');
            });

            Route::middleware('can:settings.zones')->group(function () {
                Route::get('/zones', [ZoneController::class, 'index'])->name('zones.index');
                Route::post('/zones', [ZoneController::class, 'store'])->name('zones.store');
                Route::delete('/zones/{zone}', [ZoneController::class, 'destroy'])->name('zones.destroy');
            });

            // شحنات للمعالجة: قرار المتابعة في كل محاولةٍ فاشلة
            Route::middleware('can:shipments.status')->group(function () {
                Route::get('/processing', [ProcessingController::class, 'index'])->name('processing.index');
                Route::post('/processing/{shipment}', [ProcessingController::class, 'store'])->name('processing.store');
            });

            Route::middleware('can:control.review')->group(function () {
                Route::get('/control/review', [ReviewHoldController::class, 'index'])->name('control.review');
                Route::post('/control/review', [ReviewHoldController::class, 'approve'])->name('control.review.approve');
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

            // المالية منها لمن يرى أرباح الشركة وحده
            Route::middleware('can:reports.financial')->group(function () {
                Route::get('/reports/profit', [ReportController::class, 'profit'])->name('reports.profit');
                Route::get('/reports/returns-money', [ReportController::class, 'returnsMoney'])->name('reports.returns-money');
                Route::get('/reports/courier-overcharge', [ReferenceReportController::class, 'courierOvercharge'])->name('reports.courier-overcharge');
                Route::get('/reports/merchant-profit', [ReferenceReportController::class, 'merchantProfit'])->name('reports.merchant-profit');
            });

            // عشرة تقارير لا واحد وثلاثون
            Route::middleware('can:reports.view')->group(function () {
            Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('/reports/returns', [ReportController::class, 'returns'])->name('reports.returns');
            Route::get('/reports/couriers', [ReportController::class, 'couriers'])->name('reports.couriers');
            Route::get('/reports/merchants', [ReportController::class, 'merchants'])->name('reports.merchants');
            Route::get('/reports/governorates', [ReportController::class, 'governorates'])->name('reports.governorates');
            Route::get('/reports/daily', [ReportController::class, 'daily'])->name('reports.daily');
            Route::get('/reports/dormant', [ReportController::class, 'dormant'])->name('reports.dormant');
            Route::get('/reports/debtors', [ReportController::class, 'debtors'])->name('reports.debtors');
            Route::get('/reports/changes', [ReportController::class, 'changes'])->name('reports.changes');
            // وما في المعتاد غيرها، كلٌّ بسؤاله
            Route::get('/reports/entries', [ReferenceReportController::class, 'entries'])->name('reports.entries');
            Route::get('/reports/portal', [ReferenceReportController::class, 'portal'])->name('reports.portal');
            Route::get('/reports/processing', [ReferenceReportController::class, 'processing'])->name('reports.processing');
            Route::get('/reports/stuck', [ReferenceReportController::class, 'stuck'])->name('reports.stuck');
            Route::get('/reports/special-prices', [ReferenceReportController::class, 'specialPrices'])->name('reports.special-prices');
            Route::get('/reports/unconfirmed', [ReferenceReportController::class, 'unconfirmed'])->name('reports.unconfirmed');
            });

            // المحادثات مع التجّار: للشركة لا لموظّفٍ بعينه
            Route::middleware('can:support.reply')->group(function () {
                Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
                Route::post('/conversations', [ConversationController::class, 'store'])->name('conversations.store');
                Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
                Route::post('/conversations/{conversation}/reply', [ConversationController::class, 'reply'])->name('conversations.reply');
                Route::post('/conversations/{conversation}/close', [ConversationController::class, 'close'])->name('conversations.close');
            });

            // بيانات الشركة: الهاتف وواتساب الدعم واللون
            Route::middleware('can:settings.company')->group(function () {
                Route::get('/settings/company', [CompanySettingsController::class, 'edit'])->name('settings.company');
                Route::put('/settings/company', [CompanySettingsController::class, 'update'])->name('settings.company.update');
            });

            // الإشعارات الجماعية: إعلانٌ واحد للمناديب أو للتجّار، ومَن قرأه
            Route::middleware('can:notify.send')->group(function () {
                Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
                Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
                Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show'])->name('announcements.show');
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
            Route::get('/manifests/archive', [ManifestController::class, 'archive'])->name('manifests.archive');
            Route::get('/manifests/{manifest}/print', [ManifestController::class, 'print'])->name('manifests.print');
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

            // تسديد ديون الفروع واستلامها: نقدٌ يخرج من صندوقٍ ويدخل آخر
            Route::middleware('can:money.cash')->group(function () {
                Route::post('/money/position', [FinancialPositionController::class, 'store'])->name('money.position.store');
                Route::post('/branch-accounts/remittances', [BranchRemittanceController::class, 'send'])->name('branch-accounts.remit');
                Route::post('/branch-accounts/remittances/{remittance}/receive', [BranchRemittanceController::class, 'receive'])
                    ->whereNumber('remittance')->name('branch-accounts.receive');
            });

            // «صندوقي»: لصاحب صندوق الموظّف وحده
            Route::middleware('can:cash.own-box')->group(function () {
                Route::get('/cash/mine', [MyCashBoxController::class, 'index'])->name('cash.mine');
                Route::post('/cash/mine/handover', [MyCashBoxController::class, 'handover'])->name('cash.mine.handover');
            });

            // محاسبة الفروع وتأميناتها
            Route::middleware('can:money.view')->group(function () {
                Route::get('/money/accountants', AccountantAccountsController::class)->name('money.accountants');
                Route::get('/money/position', [FinancialPositionController::class, 'index'])->name('money.position');
                Route::get('/money/position/history', [FinancialPositionController::class, 'history'])->name('money.position.history');
                Route::get('/branch-accounts/debts', [BranchRemittanceController::class, 'debts'])->name('branch-accounts.debts');
                Route::get('/branch-accounts/remittances', [BranchRemittanceController::class, 'inbox'])->name('branch-accounts.remittances');
                Route::get('/branch-accounts', [BranchAccountController::class, 'index'])->name('branch-accounts.index');
                Route::get('/branch-accounts/deposits', [BranchAccountController::class, 'deposits'])->name('branch-accounts.deposits');
                Route::get('/branch-accounts/statement', [BranchAccountController::class, 'statement'])->name('branch-accounts.statement');
                Route::get('/money/reconcile', ReconcileController::class)->name('money.reconcile');
                Route::get('/branch-accounts/statement/print', [BranchAccountController::class, 'statementPrint'])->name('branch-accounts.statement.print');
            });
            Route::post('/branch-accounts/deposits', [BranchAccountController::class, 'storeDeposit'])
                ->middleware('can:money.cash')->name('branch-accounts.deposits.store');

            Route::middleware('can:money.expenses')->group(function () {
                Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
                Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
                Route::post('/expenses/archive', [ExpenseController::class, 'archive'])->name('expenses.archive');
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
            Route::resource('merchants', MerchantController::class)->except(['destroy'])->middleware('can:settings.merchants');
            Route::resource('couriers', CourierController::class)->except(['destroy'])->middleware('can:settings.couriers');
            Route::resource('users', UserController::class)->except(['destroy', 'show'])
                ->middleware('can:settings.users');
            Route::resource('branches', BranchController::class)->except(['destroy', 'show'])
                ->middleware('can:settings.branches');

            Route::middleware('can:settings.pricing')->group(function () {
                // أجور المناطق والأطراف، وإعدادات المحافظات: التسعير كما تراه الشركة
                Route::get('/areas', [AreaController::class, 'index'])->name('areas.index');
                Route::post('/areas', [AreaController::class, 'update'])->name('areas.update');
                Route::post('/areas/peripheral', [AreaController::class, 'peripheral'])->name('areas.peripheral');
                Route::get('/governorate-settings', [GovernorateSettingController::class, 'index'])->name('governorate-settings.index');
                Route::post('/governorate-settings', [GovernorateSettingController::class, 'update'])->name('governorate-settings.update');

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
                Route::post('couriers/team', [CourierSettlementController::class, 'team'])
                    ->middleware('can:money.settle')->name('couriers.team');
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
            Route::post('/payouts/{payout}/confirm', [CourierShareController::class, 'confirm'])->whereNumber('payout')->name('payouts.confirm');

            Route::get('/', [TaskController::class, 'index'])->name('tasks');
            Route::get('/search', [TaskController::class, 'search'])->name('search');
            Route::get('/today', [TaskController::class, 'today'])->name('today');
            Route::get('/cash', CourierCashController::class)->name('cash');
            Route::get('/inbox', [InboxController::class, 'courier'])->name('inbox');
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
            Route::get('/shipments/labels', [ShipmentLabelController::class, 'portal'])->name('shipments.labels');
            Route::get('/shipments/{shipment}', [PortalShipmentController::class, 'show'])->name('shipments.show');

            Route::get('/statement', StatementController::class)->name('statement');
            Route::post('/settlements/{settlement}/confirm', [StatementController::class, 'confirm'])->whereNumber('settlement')->name('settlements.confirm');
            Route::get('/inbox', [InboxController::class, 'portal'])->name('inbox');

            Route::get('/support', [PortalSupportController::class, 'index'])->name('support.index');
            Route::post('/support', [PortalSupportController::class, 'store'])->name('support.store');
            Route::get('/support/{conversation}', [PortalSupportController::class, 'show'])->name('support.show');
            Route::post('/support/{conversation}/reply', [PortalSupportController::class, 'reply'])->name('support.reply');

            Route::get('/pickups', [PickupRequestController::class, 'index'])->name('pickups.index');
            Route::post('/pickups', [PickupRequestController::class, 'store'])->name('pickups.store');

            Route::get('/processing', [PortalProcessingController::class, 'index'])->name('processing.index');
            Route::post('/processing/{shipment}', [PortalProcessingController::class, 'store'])->whereNumber('shipment')->name('processing.store');

            Route::get('/requests', [PortalRequestController::class, 'index'])->name('requests.index');
            Route::post('/requests', [PortalRequestController::class, 'store'])->name('requests.store');
            Route::post('/requests/{merchantRequest}/cancel', [PortalRequestController::class, 'cancel'])->whereNumber('merchantRequest')->name('requests.cancel');
            Route::post('/requests/returns/{batch}/confirm', [PortalRequestController::class, 'confirm'])->whereNumber('batch')->name('requests.returns.confirm');
            Route::get('/requests/returns/{batch}/print', [PortalRequestController::class, 'print'])->whereNumber('batch')->name('requests.returns.print');
        });
    });
});

/*
| سؤال خادم الويب قبل إصدار شهادة HTTPS لنطاقٍ جديد (انظر deploy/Caddyfile).
| خارج مجموعة tenant: السائل هو الخادم نفسه، لا زائرٌ على نطاق شركة.
*/
Route::get('/_tls/allowed', TlsAskController::class)->name('tls.ask');

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
        Route::get('/companies/{company}/edit', [PlatformCompanyController::class, 'edit'])->name('companies.edit');
        Route::put('/companies/{company}', [PlatformCompanyController::class, 'update'])->name('companies.update');
        Route::post('/companies/{company}/suspend', [PlatformCompanyController::class, 'suspend'])->name('companies.suspend');
        Route::post('/companies/{company}/activate', [PlatformCompanyController::class, 'activate'])->name('companies.activate');
        Route::post('/companies/{company}/impersonate', [ImpersonationController::class, 'start'])->name('companies.impersonate');

        // مال المنصّة مع الشركات: اشتراك كلٍّ منها وما عليها. أسعار التوصيل ليست هنا —
        // تلك بين الشركة وتجّارها، في «التسعيرات» من نظامها
        Route::get('/subscriptions', [PlatformSubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('/subscriptions/{company}', [PlatformSubscriptionController::class, 'show'])->name('subscriptions.show');
        Route::post('/subscriptions/{company}', [PlatformSubscriptionController::class, 'store'])->name('subscriptions.store');
        Route::post('/subscriptions/{company}/cancel', [PlatformSubscriptionController::class, 'cancel'])->name('subscriptions.cancel');

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

// الدخول يُصرَف وينتهي داخل نظام الشركة، فهما في مجموعة المستأجر:
// نطاق الشركة يصرف تذكرةً كتبتها لوحة المنصّة (ImpersonateCompany).
// والصرف يستبدل مَن في الجلسة بصاحب التذكرة — وقد فُحص أنه من هذه
// الشركة — فلا معنى لطرد مَن كان فيها قبله: على مضيفٍ واحدٍ في التطوير
// كان هو المدير نفسه، فيُطرَد إلى الدخول قبل أن تُصرَف تذكرته.
Route::middleware('tenant')
    ->withoutMiddleware(\App\Http\Middleware\EnsureUserBelongsToTenant::class)
    ->get('/impersonate/{token}', [ImpersonationController::class, 'enter'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('impersonation.enter');

Route::middleware(['tenant', 'auth'])
    ->post('/stop-impersonating', [ImpersonationController::class, 'stop'])
    ->name('impersonation.stop');
