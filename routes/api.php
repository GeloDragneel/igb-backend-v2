<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ChartOfAccountController;
use App\Http\Controllers\Api\VendorCustomerController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\BirReturnController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\ImportationController;
use App\Http\Controllers\Api\ExportationController;
use App\Http\Controllers\Api\ReceiveInventoryController;
use App\Http\Controllers\Api\GeneralJournalController;
use App\Http\Controllers\Api\FixedAssetController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EngagementController;
use App\Http\Controllers\Api\SetupListController;
use App\Http\Controllers\Api\BankingController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\ReceiveMoneyController;
use App\Http\Controllers\Api\PayBillController;
use App\Http\Controllers\Api\InvoiceExtrasController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\MonitoringController;
use App\Http\Controllers\Api\IMSDashboardController;
use App\Http\Controllers\Api\HRManagmentController as HR;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\HRDashboardController;
use App\Http\Controllers\Api\AdministrationController;
use App\Http\Controllers\Api\PrintController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\AiAssistantController; 
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/branches', [BranchController::class, 'index']);
    Route::post('/switch-branch', [AuthController::class, 'switchBranch']);
    Route::get('/staff-access', [AuthController::class, 'staffAccess']);

    Route::get('/dashboard', [DashboardController::class, 'summary']);

    Route::get('/sales/lookups', [SalesController::class, 'lookups']);
    Route::post('/sales/declared-month', [SalesController::class, 'updateDeclaredMonth']);
    Route::get('/sales', [SalesController::class, 'index']);
    Route::post('/sales', [SalesController::class, 'store']);
    Route::get('/sales/invoice/{invoiceNo}', [SalesController::class, 'show']);
    Route::get('/sales/invoice/{invoiceNo}/journal', [SalesController::class, 'journal']);
    Route::put('/sales/{id}', [SalesController::class, 'update'])->whereNumber('id');
    Route::post('/sales/{id}/status', [SalesController::class, 'setStatus'])->whereNumber('id');
    Route::post('/customers', [SalesController::class, 'storeCustomer']);
    Route::post('/items', [SalesController::class, 'storeItem']);
    Route::get('/sales/invoice/{invoiceNo}/neighbors', [SalesController::class, 'neighbors']);
    Route::post('/sales/{id}/audit', [SalesController::class, 'audit'])->whereNumber('id');
    Route::post('/sales/{id}/ticket', [SalesController::class, 'requestTicket'])->whereNumber('id');
    Route::get('/sales/{id}/attachment', [SalesController::class, 'attachment'])->whereNumber('id');
    Route::post('/sales/{id}/attachment', [SalesController::class, 'uploadAttachment'])->whereNumber('id');
    Route::get('/sales/ticket/{ticketNo}', [SalesController::class, 'ticketShow']);
    Route::get('/sales/scanned', [SalesController::class, 'scanned']);
    Route::get('/sales/scanned/{id}/file', [SalesController::class, 'scannedFile'])->whereNumber('id');
    Route::post('/sales/scanned/{id}/read', [SalesController::class, 'readScan'])->whereNumber('id');
    Route::post('/sales/scanned/{id}/transfer', [SalesController::class, 'transferScan'])->whereNumber('id');
    Route::post('/sales/{id}/post', [SalesController::class, 'postInvoice'])->whereNumber('id');
    Route::post('/sales/override', [SalesController::class, 'override']);
    Route::get('/sales/blank-attachment', [SalesController::class, 'blankAttachment']);

    // routes/api.php – inside the auth:sanctum group.
    // Put them ABOVE any route like /sales/{id} so "document-check" isn't read as an id.
    Route::get('/sales/document-check', [SalesController::class, 'documentCheck']);
    Route::post('/sales/document-override', [SalesController::class, 'documentOverride']);
    Route::get('/purchases/document-check', [PurchaseController::class, 'documentCheck']);
    Route::post('/purchases/document-override', [PurchaseController::class, 'documentOverride']);
    Route::get('/expenses/document-check', [ExpenseController::class, 'documentCheck']);
    Route::post('/expenses/document-override', [ExpenseController::class, 'documentOverride']);


    Route::get('/accounts', [ChartOfAccountController::class, 'listAccounts']);
    Route::post('/accounts', [ChartOfAccountController::class, 'storeAccount']);
    Route::put('/accounts/{code}', [ChartOfAccountController::class, 'updateAccount']);
    Route::delete('/accounts/{code}', [ChartOfAccountController::class, 'destroyAccount']);

    Route::get('/contacts-lookups', [VendorCustomerController::class, 'contactLookups']);
    Route::get('/contacts-sync/{caid}', [VendorCustomerController::class, 'syncClient'])->whereNumber('caid');
    Route::get('/contacts/{type}', [VendorCustomerController::class, 'listContacts'])->whereIn('type', ['customer', 'vendor']);
    Route::post('/contacts/{type}', [VendorCustomerController::class, 'storeContact'])->whereIn('type', ['customer', 'vendor']);
    Route::put('/contacts/{type}/{id}', [VendorCustomerController::class, 'updateContact'])->whereIn('type', ['customer', 'vendor'])->whereNumber('id');
    Route::post('/contacts/{type}/{id}/default', [VendorCustomerController::class, 'setDefaultContact'])->whereIn('type', ['customer', 'vendor'])->whereNumber('id');

    Route::get('/products-lookups', [ProductController::class, 'productLookups']);
    Route::get('/products/beginning', [ProductController::class, 'begBalances']);
    Route::post('/products/beginning', [ProductController::class, 'saveBegBalance']);
    Route::get('/products', [ProductController::class, 'listProducts']);
    Route::post('/products', [ProductController::class, 'storeProduct']);
    Route::post('/products/{id}', [ProductController::class, 'updateProduct'])->whereNumber('id');
    Route::delete('/products/{id}', [ProductController::class, 'destroyProduct'])->whereNumber('id');
    Route::get('/products/{id}/units', [ProductController::class, 'productUnits'])->whereNumber('id');
    Route::get('/products/{id}/price-history', [ProductController::class, 'priceHistory'])->whereNumber('id');
    Route::get('/products/{id}/image', [ProductController::class, 'productImage'])->whereNumber('id');

    Route::get('/journals/sales', [ReportController::class, 'salesJournal']);
    Route::get('/journals/purchases', [ReportController::class, 'purchasesJournal']);
    Route::get('/journals/expenses', [ReportController::class, 'expensesJournal']);
    Route::get('/journals/general-journal', [ReportController::class, 'generalJournal']);
    
    Route::get('/reports/sales', [ReportController::class, 'salesReport']);
    Route::get('/reports/purchase', [ReportController::class, 'purchaseReport']);
    Route::get('/reports/expense', [ReportController::class, 'expenseReport']);

    Route::get('/ledgers/general', [ReportController::class, 'generalLedger']);
    Route::get('/ledgers/summary', [ReportController::class, 'summaryLedger']);
    Route::get('/ledgers/customer', [ReportController::class, 'customerLedger']);
    Route::get('/ledgers/vendor', [ReportController::class, 'vendorLedger']);

    Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance']);
    Route::get('/reports/balance-sheet', [ReportController::class, 'balanceSheet']);
    Route::get('/reports/working-trial-balance', [ReportController::class, 'workingTrialBalance']);
    Route::post('/reports/working-trial-balance', [ReportController::class, 'saveWorkingTrialBalance']);
    Route::get('/reports/consolidated', [ReportController::class, 'consolidatedReport']);
    Route::get('/reports/annual-summary', [ReportController::class, 'annualSummary']);
    Route::get('/reports/annual-summary/docs', [ReportController::class, 'annualSummaryDocs']);
    Route::get('/reports/cash-flow', [ReportController::class, 'cashFlow']);
    Route::get('/reports/retained-earnings', [ReportController::class, 'retainedEarnings']);
    Route::get('/reports/changes-in-equity', [ReportController::class, 'changesInEquity']);

    Route::get('/bir/profile', [BirReturnController::class, 'profile']);
    Route::get('/bir/atc-2551q', [BirReturnController::class, 'atc2551q']);
    Route::get('/bir/autofill', [BirReturnController::class, 'autofill']);
    Route::get('/bir/files', [BirReturnController::class, 'files']);
    Route::post('/bir/files', [BirReturnController::class, 'saveFile']);
    Route::get('/bir/files/{name}', [BirReturnController::class, 'readFile']);
    Route::delete('/bir/files/{name}', [BirReturnController::class, 'deleteFile']);
    Route::get('/bir/dat-file', [BirReturnController::class, 'datFile']);
    Route::get('/bir/filing/{name}', [BirReturnController::class, 'filing']);
    Route::post('/bir/filing/{name}', [BirReturnController::class, 'fileReturn']);

    // routes/api.php – inside the auth:sanctum group, next to the other /bir routes
    Route::get('/bir/filing/{name}/payments', [BirReturnController::class, 'payments']);
    Route::post('/bir/filing/{name}/payments', [BirReturnController::class, 'payStore']);
    Route::delete('/bir/payments/{id}', [BirReturnController::class, 'payDelete']);
    Route::get('/bir/payments/{id}/attachment', [BirReturnController::class, 'payAttachment']);


    Route::get('/reports/bir2307', [ReportController::class, 'bir2307Summary']);
    Route::get('/reports/vat-summary', [ReportController::class, 'vatSummary']);
    Route::get('/reports/vat-summary/docs', [ReportController::class, 'vatSummaryDocs']);
    Route::get('/reports/esub', [ReportController::class, 'esubReport']);
    Route::get('/reports/income-tax-summary', [ReportController::class, 'incomeTaxSummary']);
    Route::get('/reports/income-tax-summary/docs', [ReportController::class, 'incomeTaxDocs']);
    Route::get('/reports/income-tax-summary/nonvat', [ReportController::class, 'incomeTaxNonVat']);
    Route::get('/reports/income-tax-summary/adjustments', [ReportController::class, 'incomeTaxAdjustments']);
    Route::post('/reports/income-tax-summary/adjustments', [ReportController::class, 'saveIncomeTaxAdjustment']);

    Route::get('/report-generator/clients', [ReportController::class, 'reportGeneratorClients']);
    Route::get('/report-generator/generate', [ReportController::class, 'reportGeneratorGenerate']);
    Route::get('/reports/transactions', [ReportController::class, 'transactionsList']);

    Route::get('/team', [TeamController::class, 'index']);
    Route::post('/team', [TeamController::class, 'store']);
    Route::put('/team/{id}', [TeamController::class, 'update'])->whereNumber('id');
    Route::delete('/team/{id}', [TeamController::class, 'destroy'])->whereNumber('id');

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::get('/settings/cities', [SettingsController::class, 'cities']);
    Route::get('/settings/barangays', [SettingsController::class, 'barangays']);
    Route::put('/settings/client', [SettingsController::class, 'updateClient']);
    Route::post('/settings/accounts', [SettingsController::class, 'storeAccount']);
    Route::put('/settings/accounts/{id}', [SettingsController::class, 'updateAccount'])->whereNumber('id');
    Route::delete('/settings/accounts/{id}', [SettingsController::class, 'destroyAccount'])->whereNumber('id');
    Route::post('/settings/valid-ids', [SettingsController::class, 'storeValidId']);
    Route::delete('/settings/valid-ids/{id}', [SettingsController::class, 'destroyValidId'])->whereNumber('id');
    Route::get('/settings', [SettingsController::class, 'settingsShow']);
    Route::put('/settings/valid-ids/{id}', [SettingsController::class, 'settingsUpdateValidId'])->whereNumber('id');

    // ---------- Purchases ----------
    Route::get('/purchases/lookups', [PurchaseController::class, 'apLookups']);
    Route::get('/purchases/blank-attachment', [PurchaseController::class, 'apBlankAttachment']);
    Route::get('/purchases/scanned', [PurchaseController::class, 'apScanned']);
    Route::get('/purchases/scanned/{id}/file', [PurchaseController::class, 'apScannedFile'])->whereNumber('id');
    Route::post('/purchases/scanned/{id}/read', [PurchaseController::class, 'apReadScan'])->whereNumber('id');
    Route::post('/purchases/scanned/{id}/transfer', [PurchaseController::class, 'apTransferScan'])->whereNumber('id');
    Route::post('/purchases/override', [PurchaseController::class, 'apOverride']);
    Route::get('/purchases/ticket/{ticketNo}', [PurchaseController::class, 'apTicketShow']);
    Route::get('/purchases/invoice/{invoiceNo}', [PurchaseController::class, 'apShow']);
    Route::get('/purchases/invoice/{invoiceNo}/neighbors', [PurchaseController::class, 'apNeighbors']);
    Route::get('/purchases/invoice/{invoiceNo}/journal', [PurchaseController::class, 'apJournal']);
    Route::get('/purchases', [PurchaseController::class, 'apIndex']);
    Route::post('/purchases', [PurchaseController::class, 'apStore']);
    Route::get('/purchases/{id}/attachment', [PurchaseController::class, 'apAttachment'])->whereNumber('id');
    Route::post('/purchases/{id}/attachment', [PurchaseController::class, 'apUploadAttachment'])->whereNumber('id');
    Route::post('/purchases/{id}/ticket', [PurchaseController::class, 'apTicket'])->whereNumber('id');
    Route::post('/purchases/{id}/audit', [PurchaseController::class, 'apAudit'])->whereNumber('id');
    Route::post('/purchases/{id}/post', [PurchaseController::class, 'apPost'])->whereNumber('id');
    
    // ---------- Expenses ----------
    Route::get('/expenses/lookups', [ExpenseController::class, 'apLookups']);
    Route::get('/expenses/blank-attachment', [ExpenseController::class, 'apBlankAttachment']);
    Route::get('/expenses/scanned', [ExpenseController::class, 'apScanned']);
    Route::get('/expenses/scanned/{id}/file', [ExpenseController::class, 'apScannedFile'])->whereNumber('id');
    Route::post('/expenses/scanned/{id}/read', [ExpenseController::class, 'apReadScan'])->whereNumber('id');
    Route::post('/expenses/scanned/{id}/transfer', [ExpenseController::class, 'apTransferScan'])->whereNumber('id');
    Route::post('/expenses/override', [ExpenseController::class, 'apOverride']);
    Route::get('/expenses/ticket/{ticketNo}', [ExpenseController::class, 'apTicketShow']);
    Route::get('/expenses/invoice/{invoiceNo}', [ExpenseController::class, 'apShow']);
    Route::get('/expenses/invoice/{invoiceNo}/neighbors', [ExpenseController::class, 'apNeighbors']);
    Route::get('/expenses/invoice/{invoiceNo}/journal', [ExpenseController::class, 'apJournal']);
    Route::get('/expenses', [ExpenseController::class, 'apIndex']);
    Route::post('/expenses', [ExpenseController::class, 'apStore']);
    Route::get('/expenses/{id}/attachment', [ExpenseController::class, 'apAttachment'])->whereNumber('id');
    Route::post('/expenses/{id}/attachment', [ExpenseController::class, 'apUploadAttachment'])->whereNumber('id');
    Route::post('/expenses/{id}/ticket', [ExpenseController::class, 'apTicket'])->whereNumber('id');
    Route::post('/expenses/{id}/audit', [ExpenseController::class, 'apAudit'])->whereNumber('id');
    Route::post('/expenses/{id}/post', [ExpenseController::class, 'apPost'])->whereNumber('id');

    // ---------- Purchase Orders (Purchases tab) ----------
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'poIndex']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'poStore']);
    Route::get('/purchase-orders/blank-attachment', [PurchaseOrderController::class, 'apBlankAttachment']);
    Route::get('/purchase-orders/ticket/{ticketNo}', [PurchaseOrderController::class, 'poTicketShow']);
    Route::get('/purchase-orders/invoice/{poNumber}', [PurchaseOrderController::class, 'poShow']);
    Route::get('/purchase-orders/invoice/{poNumber}/journal', [PurchaseOrderController::class, 'poJournal']);
    Route::get('/purchase-orders/{id}/attachment', [PurchaseOrderController::class, 'poAttachment'])->whereNumber('id');
    Route::post('/purchase-orders/{id}/attachment', [PurchaseOrderController::class, 'poUploadAttachment'])->whereNumber('id');
    Route::post('/purchase-orders/{id}/ticket', [PurchaseOrderController::class, 'poTicket'])->whereNumber('id');

    Route::get('/importation', [ImportationController::class, 'trIndex']);
    Route::post('/importation', [ImportationController::class, 'trStore']);
    Route::get('/importation/lookups', [ImportationController::class, 'trLookups']);
    Route::post('/importation/parties', [ImportationController::class, 'trStoreParty']);
    Route::get('/importation/ticket/{ticketNo}', [ImportationController::class, 'trTicketShow']);
    Route::get('/importation/record/{no}', [ImportationController::class, 'trShow']);
    Route::get('/importation/record/{no}/journal', [ImportationController::class, 'trJournal']);
    Route::post('/importation/{id}/ticket', [ImportationController::class, 'trTicket'])->whereNumber('id');
    Route::get('/importation/{id}/attachment', [ImportationController::class, 'trAttachment'])->whereNumber('id');
    Route::post('/importation/{id}/attachment', [ImportationController::class, 'trUploadAttachment'])->whereNumber('id');

    // ---------- Exportation ----------
    Route::get('/exportation', [ExportationController::class, 'trIndex']);
    Route::post('/exportation', [ExportationController::class, 'trStore']);
    Route::get('/exportation/lookups', [ExportationController::class, 'trLookups']);
    Route::post('/exportation/parties', [ExportationController::class, 'trStoreParty']);
    Route::get('/exportation/ticket/{ticketNo}', [ExportationController::class, 'trTicketShow']);
    Route::get('/exportation/record/{no}', [ExportationController::class, 'trShow']);
    Route::get('/exportation/record/{no}/journal', [ExportationController::class, 'trJournal']);
    Route::post('/exportation/{id}/ticket', [ExportationController::class, 'trTicket'])->whereNumber('id');
    Route::get('/exportation/{id}/attachment', [ExportationController::class, 'trAttachment'])->whereNumber('id');
    Route::post('/exportation/{id}/attachment', [ExportationController::class, 'trUploadAttachment'])->whereNumber('id');

    // ---------- Inventory → Receive Inventory ----------
    Route::get('/inventory/receive', [ReceiveInventoryController::class, 'riIndex']);
    Route::post('/inventory/receive', [ReceiveInventoryController::class, 'riStore']);
    Route::get('/inventory/receive/lookups', [ReceiveInventoryController::class, 'riLookups']);
    Route::post('/inventory/receive/locations', [ReceiveInventoryController::class, 'riStoreLocation']);
    Route::get('/inventory/receive/sources', [ReceiveInventoryController::class, 'riSources']);
    Route::get('/inventory/receive/source/{ref}', [ReceiveInventoryController::class, 'riSource']);
    Route::get('/inventory/receive/record/{grnNo}', [ReceiveInventoryController::class, 'riShow']);
    Route::get('/inventory/receive/record/{grnNo}/journal', [ReceiveInventoryController::class, 'riJournal']);
    Route::put('/inventory/receive/{id}', [ReceiveInventoryController::class, 'riUpdate'])->whereNumber('id');
    Route::post('/inventory/receive/{id}/void', [ReceiveInventoryController::class, 'riVoid'])->whereNumber('id');
    Route::get('/inventory/receive/{id}/attachment', [ReceiveInventoryController::class, 'riAttachment'])->whereNumber('id');
    Route::post('/inventory/receive/{id}/attachment', [ReceiveInventoryController::class, 'riUploadAttachment'])->whereNumber('id');
    
    
    // ---------- Inventory → Stocks ----------
    Route::get('/inventory/stocks/lookups', [ReceiveInventoryController::class, 'stLookups']);
    Route::get('/inventory/stocks/ledger', [ReceiveInventoryController::class, 'stLedger']);
    Route::get('/inventory/stocks/details', [ReceiveInventoryController::class, 'stDetails']);
    Route::get('/inventory/stocks/adjustments', [ReceiveInventoryController::class, 'stAdjustmentList']);
    Route::post('/inventory/stocks/adjustments', [ReceiveInventoryController::class, 'stSaveAdjustment']);
    Route::put('/inventory/stocks/adjustments/{id}', [ReceiveInventoryController::class, 'stUpdateAdjustment'])->whereNumber('id');
    Route::delete('/inventory/stocks/adjustments/{id}', [ReceiveInventoryController::class, 'stDeleteAdjustment'])->whereNumber('id');

    // ---------- Inventory → Adjustment (sales-type; lists come from GET /sales/lookups) ----------
    Route::get('/inventory/adjustment', [ReceiveInventoryController::class, 'adjIndex']);
    Route::post('/inventory/adjustment', [ReceiveInventoryController::class, 'adjStore']);
    Route::get('/inventory/adjustment/blank-attachment', [SalesController::class, 'blankAttachment']);
    Route::get('/inventory/adjustment/invoice/{invoiceNo}', [ReceiveInventoryController::class, 'adjShow']);
    Route::get('/inventory/adjustment/invoice/{invoiceNo}/journal', [ReceiveInventoryController::class, 'adjJournal']);
    Route::get('/inventory/adjustment/invoice/{invoiceNo}/neighbors', [ReceiveInventoryController::class, 'adjNeighbors']);
    Route::put('/inventory/adjustment/{id}', [ReceiveInventoryController::class, 'adjUpdate'])->whereNumber('id');
    Route::post('/inventory/adjustment/{id}/audit', [ReceiveInventoryController::class, 'adjAudit'])->whereNumber('id');
    Route::get('/inventory/adjustment/{id}/attachment', [ReceiveInventoryController::class, 'adjAttachment'])->whereNumber('id');
    Route::post('/inventory/adjustment/{id}/attachment', [ReceiveInventoryController::class, 'adjUploadAttachment'])->whereNumber('id');
    
    // ---------- Inventory → Internal Transfer ----------
    Route::get('/inventory/transfers', [ReceiveInventoryController::class, 'itIndex']);
    Route::get('/inventory/transfers/{transferNo}', [ReceiveInventoryController::class, 'itDetails']);

    // ---------- Task → General Journal ----------
    Route::get('/general-journal', [GeneralJournalController::class, 'gjIndex']);
    Route::post('/general-journal', [GeneralJournalController::class, 'gjSave']);
    Route::get('/general-journal/accounts', [GeneralJournalController::class, 'gjAccounts']);
    Route::get('/general-journal/entry/{jvNo}', [GeneralJournalController::class, 'gjShow']);
    Route::delete('/general-journal/entry/{jvNo}', [GeneralJournalController::class, 'gjDelete']);

    // ---------- Task → Fixed Asset Management ----------
    Route::get('/fixed-assets', [FixedAssetController::class, 'faIndex']);
    Route::post('/fixed-assets', [FixedAssetController::class, 'faStore']);
    Route::get('/fixed-assets/accounts', [FixedAssetController::class, 'faAccounts']);
    Route::get('/fixed-assets/depreciation/preview', [FixedAssetController::class, 'faPreview']);
    Route::post('/fixed-assets/depreciation/post', [FixedAssetController::class, 'faPost']);
    Route::put('/fixed-assets/{id}', [FixedAssetController::class, 'faUpdate'])->whereNumber('id');
    Route::delete('/fixed-assets/{id}', [FixedAssetController::class, 'faDelete'])->whereNumber('id');
    Route::get('/fixed-assets/{id}/schedule', [FixedAssetController::class, 'faSchedule'])->whereNumber('id');
    Route::post('/fixed-assets/{id}/dispose', [FixedAssetController::class, 'faDispose'])->whereNumber('id');
    Route::post('/fixed-assets/{id}/restore', [FixedAssetController::class, 'faRestore'])->whereNumber('id');

    Route::get('/engagements/{type}', [EngagementController::class, 'engIndex'])->whereIn('type', ['import', 'export']);
    Route::post('/engagements/{type}', [EngagementController::class, 'engStore'])->whereIn('type', ['import', 'export']);
    Route::put('/engagements/{type}/{id}', [EngagementController::class, 'engUpdate'])->whereIn('type', ['import', 'export'])->whereNumber('id');
    Route::delete('/engagements/{type}/{id}', [EngagementController::class, 'engDestroy'])->whereIn('type', ['import', 'export'])->whereNumber('id');

    // ---------- Setup → Discount List ----------
    Route::get('/discount-list', [SetupListController::class, 'dlIndex']);
    Route::post('/discount-list', [SetupListController::class, 'dlStore']);
    Route::put('/discount-list/{id}', [SetupListController::class, 'dlUpdate'])->whereNumber('id');
    Route::delete('/discount-list/{id}', [SetupListController::class, 'dlDestroy'])->whereNumber('id');

    // ---------- Setup → Store / Warehouse ----------
    Route::get('/store-warehouse', [SetupListController::class, 'swIndex']);
    Route::post('/store-warehouse', [SetupListController::class, 'swStore']);
    Route::put('/store-warehouse/{id}', [SetupListController::class, 'swUpdate'])->whereNumber('id');
    Route::delete('/store-warehouse/{id}', [SetupListController::class, 'swDestroy'])->whereNumber('id');
    Route::post('/store-warehouse/{id}/restore', [SetupListController::class, 'swRestore'])->whereNumber('id');

    // ---------- Task → Banking → Collection ----------
    Route::get('/banking/collections', [BankingController::class, 'colIndex']);
    Route::get('/banking/collections/{no}', [BankingController::class, 'colDetails']);
    Route::get('/banking/collections/{no}/journal', [BankingController::class, 'colJournal']);

    // ---------- Task → Banking → Payment ----------
    Route::get('/banking/payments', [BankingController::class, 'payIndex']);
    Route::get('/banking/payments/{no}', [BankingController::class, 'payDetails']);
    Route::get('/banking/payments/{no}/journal', [BankingController::class, 'payJournal']);

    // ---------- Task → Banking → Petty Cash ----------
    Route::get('/banking/petty-cash', [BankingController::class, 'pcOverview']);
    Route::post('/banking/petty-cash/funds', [BankingController::class, 'pcFundSave']);
    Route::put('/banking/petty-cash/funds/{id}', [BankingController::class, 'pcFundUpdate'])->whereNumber('id');
    Route::get('/banking/petty-cash/vouchers', [BankingController::class, 'pcVouchers']);
    Route::post('/banking/petty-cash/vouchers', [BankingController::class, 'pcVoucherSave']);
    Route::put('/banking/petty-cash/vouchers/{id}', [BankingController::class, 'pcVoucherUpdate'])->whereNumber('id');
    Route::post('/banking/petty-cash/vouchers/{id}/void', [BankingController::class, 'pcVoucherVoid'])->whereNumber('id');
    Route::post('/banking/petty-cash/replenish', [BankingController::class, 'pcReplenish']);
    Route::get('/banking/petty-cash/history', [BankingController::class, 'pcHistory']);
    Route::get('/banking/petty-cash/journal/{jvNo}', [BankingController::class, 'pcJournal']);

    Route::get('/reports/financial-statement', [ReportController::class, 'financialStatement']);
    Route::get('/reports/aging', [ReportController::class, 'agingReport']);

    Route::get('/loans', [LoanController::class, 'lnIndex']);
    Route::post('/loans', [LoanController::class, 'lnStore']);
    Route::post('/loans/preview', [LoanController::class, 'lnPreview']);
    Route::post('/loans/payments/{paymentId}/void', [LoanController::class, 'lnVoidPayment'])->whereNumber('paymentId');
    Route::get('/loans/{id}', [LoanController::class, 'lnShow'])->whereNumber('id');
    Route::put('/loans/{id}', [LoanController::class, 'lnUpdate'])->whereNumber('id');
    Route::post('/loans/{id}/void', [LoanController::class, 'lnVoid'])->whereNumber('id');
    Route::post('/loans/{id}/payments', [LoanController::class, 'lnPay'])->whereNumber('id');

    // ---------- Sales → RECEIVE MONEY ----------
    Route::get('/sales/invoice/{invoiceNo}/collections', [ReceiveMoneyController::class, 'rmShow']);
    Route::post('/sales/invoice/{invoiceNo}/collections', [ReceiveMoneyController::class, 'rmStore']);
    Route::delete('/sales/collections/{rvoucherNo}', [ReceiveMoneyController::class, 'rmVoid']);

    // ---------- Purchases / Expenses → PAY BILL ----------
    Route::get('/{type}/invoice/{invoiceNo}/payments', [PayBillController::class, 'pbShow'])->whereIn('type', ['purchases', 'expenses']);
    Route::post('/{type}/invoice/{invoiceNo}/payments', [PayBillController::class, 'pbStore'])->whereIn('type', ['purchases', 'expenses']);
    Route::delete('/{type}/payments/{pvoucherNo}', [PayBillController::class, 'pbVoid'])->whereIn('type', ['purchases', 'expenses']);

    Route::get('/sales/collections/{rvoucherNo}/attachment', [ReceiveMoneyController::class, 'rmAttachment']);
    Route::get('/{type}/payments/{pvoucherNo}/attachment', [PayBillController::class, 'pbAttachment'])->whereIn('type', ['purchases', 'expenses']);

    Route::get('/{type}/invoice/{invoiceNo}/2307', [InvoiceExtrasController::class, 'form2307'])->whereIn('type', ['sales', 'purchases', 'expenses']);
    Route::get('/{type}/invoice/{invoiceNo}/logs', [InvoiceExtrasController::class, 'logs'])->whereIn('type', ['sales', 'purchases', 'expenses']);

    // ---------- Billing → SOA dashboard ----------
    Route::get('/billing/lookups', [BillingController::class, 'lookups']);
    Route::get('/billing/clients', [BillingController::class, 'clients']);
    Route::get('/billing/account-list', [BillingController::class, 'accountList']);
    Route::get('/billing/services', [BillingController::class, 'services']);
    Route::get('/billing/payments', [BillingController::class, 'payments']);
    Route::get('/billing/totals', [BillingController::class, 'totals']);
    Route::get('/billing/clients/{clientNum}/accounts', [BillingController::class, 'accounts']);
    Route::get('/billing/accounts/{caid}', [BillingController::class, 'account'])->whereNumber('caid');
    
    Route::get('/billing/soa/{transNumber}', [BillingController::class, 'soa']);
    Route::put('/billing/soa/{transNumber}/fda', [BillingController::class, 'setFda']);
    Route::post('/billing/soa/{transNumber}/close', [BillingController::class, 'close']);
    Route::get('/billing/soa/{transNumber}/print/{type}', [BillingController::class, 'printSoa']);
    Route::post('/billing/soa/{transNumber}/services', [BillingController::class, 'addService']);
    Route::post('/billing/soa/{transNumber}/payments', [BillingController::class, 'addPayment']);
    
    Route::post('/billing/services/void-cancel', [BillingController::class, 'voidCancel']);
    Route::put('/billing/services/{id}', [BillingController::class, 'updateService'])->whereNumber('id');
    Route::put('/billing/services/{id}/options', [BillingController::class, 'saveOptions'])->whereNumber('id');
    Route::get('/billing/services/{id}/logs', [BillingController::class, 'serviceLogs'])->whereNumber('id');
    
    Route::put('/billing/payments/{id}', [BillingController::class, 'updatePayment'])->whereNumber('id');
    Route::delete('/billing/payments/{id}', [BillingController::class, 'deletePayment'])->whereNumber('id');
    Route::put('/billing/payments/{id}/return', [BillingController::class, 'saveReturn'])->whereNumber('id');
    
    // ---------- Billing → Service List ----------
    Route::get('/billing/service-list/lookups', [BillingController::class, 'serviceListLookups']);
    Route::get('/billing/service-list', [BillingController::class, 'serviceList']);
    Route::post('/billing/service-list', [BillingController::class, 'addServiceList']);
    Route::put('/billing/service-list/{id}', [BillingController::class, 'updateServiceList'])->whereNumber('id');
    
    // ---------- Billing → Recurring Service ----------
    Route::get('/billing/recurring', [BillingController::class, 'recurring']);
    Route::post('/billing/recurring', [BillingController::class, 'addRecurring']);
    Route::post('/billing/recurring/copy', [BillingController::class, 'copyRecurringVersion']);
    Route::put('/billing/recurring/{id}', [BillingController::class, 'updateRecurring'])->whereNumber('id');
    Route::post('/billing/recurring/{id}/toggle', [BillingController::class, 'toggleRecurring'])->whereNumber('id');

    Route::get('/billing/generate/lookups', [BillingController::class, 'generateLookups']);
    Route::get('/billing/generate/preview', [BillingController::class, 'generatePreview']);
    Route::post('/billing/generate', [BillingController::class, 'generate']);
    Route::get('/billing/manual/lookups', [BillingController::class, 'manualLookups']);
    Route::post('/billing/manual', [BillingController::class, 'manualBill']);

    Route::get('/billing/collection/lookups', [BillingController::class, 'collectionLookups']);
    Route::get('/billing/collection', [BillingController::class, 'collection']);
    Route::post('/billing/collection/submit', [BillingController::class, 'submitCollection']);
    Route::put('/billing/collection/{id}', [BillingController::class, 'updateCollection'])->whereNumber('id');
    Route::get('/billing/deposit', [BillingController::class, 'depositList']);
    Route::post('/billing/deposit', [BillingController::class, 'deposit']);

    Route::get('/billing/return-ar/accounts', [BillingController::class, 'returnArAccounts']);
    Route::put('/billing/return-ar/services/{id}', [BillingController::class, 'updateReturnService'])->whereNumber('id');

    // ---------- Client → Masterlist ----------
    Route::get('/clients/lookups', [ClientController::class, 'lookups']);
    Route::get('/clients', [ClientController::class, 'clients']);
    Route::get('/clients/options', [ClientController::class, 'clientOptions']);
    Route::post('/clients', [ClientController::class, 'storeClient']);
    Route::get('/clients/{num}', [ClientController::class, 'show']);
    Route::put('/clients/{num}', [ClientController::class, 'updateClient']);
    Route::post('/clients/{num}/accounts', [ClientController::class, 'storeAccount']);
    Route::post('/clients/{num}/valid-ids', [ClientController::class, 'storeValidId']);
    Route::get('/client-accounts', [ClientController::class, 'allAccounts']);
    Route::put('/client-accounts/{id}', [ClientController::class, 'updateAccount'])->whereNumber('id');
    Route::delete('/client-accounts/{id}', [ClientController::class, 'destroyAccount'])->whereNumber('id');
    Route::put('/client-valid-ids/{id}', [ClientController::class, 'updateValidId'])->whereNumber('id');
    Route::delete('/client-valid-ids/{id}', [ClientController::class, 'destroyValidId'])->whereNumber('id');
    Route::get('/client-coa-template', [ClientController::class, 'coaTemplate']);

    // Assigned clients (put these ABOVE any '/client-accounts/{id}' routes)
    Route::get('/client-accounts/assigned', [ClientController::class, 'assignedAccounts']);
    Route::get('/client-accounts/assignees', [ClientController::class, 'assignees']);
    Route::put('/client-accounts/{id}/assigned', [ClientController::class, 'updateAssigned'])->whereNumber('id');

    // Master File → Nature of Business → Charts of account popup (nob 0 = general chart)
    Route::get('/nature-coa/{nob}', [ClientController::class, 'natureCoa'])->whereNumber('nob');
    Route::post('/nature-coa/{nob}/copy-general', [ClientController::class, 'natureCoaCopyGeneral'])->whereNumber('nob');
    Route::post('/nature-coa/{nob}', [ClientController::class, 'storeNatureCoa'])->whereNumber('nob');
    Route::put('/nature-coa/{nob}/{code}', [ClientController::class, 'updateNatureCoa'])->whereNumber('nob');
    Route::delete('/nature-coa/{nob}/{code}', [ClientController::class, 'destroyNatureCoa'])->whereNumber('nob');


    // Client → Master File (+ dropdowns)
    Route::get('/client-master-files', [ClientController::class, 'masterFiles']);
    Route::post('/client-master-files/{key}', [ClientController::class, 'storeMasterFile']);
    Route::put('/client-master-files/{key}/{id}', [ClientController::class, 'updateMasterFile'])->whereNumber('id');
    Route::delete('/client-master-files/{key}/{id}', [ClientController::class, 'destroyMasterFile'])->whereNumber('id');
    Route::get('/client-master-files/{key}', [ClientController::class, 'masterFileRows'])->where('key', '[a-z_]+');

    // ---------- Monitoring → Reconciliation Monitoring (year grid) ----------
    Route::get('/monitoring/reconciliation', [MonitoringController::class, 'monitoring']);
    Route::get('/monitoring/reconciliation/compliance', [MonitoringController::class, 'complianceList']);
    Route::put('/monitoring/reconciliation/compliance', [MonitoringController::class, 'toggleCompliance']);
    Route::put('/monitoring/reconciliation/flag', [MonitoringController::class, 'setFlag']);
    Route::get('/monitoring/reconciliation/{clientNum}/accounts', [MonitoringController::class, 'monitoringAccounts']);

    // ---------- Monitoring → Audit Reconciliation (diagnose) ----------
    Route::get('/monitoring/audit-reconciliation', [MonitoringController::class, 'scan']);
    Route::post('/monitoring/audit-reconciliation', [MonitoringController::class, 'save']);

    // ---------- Monitoring → Audit Monitoring ----------
    Route::get('/monitoring/audit/lookups', [MonitoringController::class, 'auditLookups']);
    Route::get('/monitoring/audit/detail', [MonitoringController::class, 'auditDetail']);
    Route::get('/monitoring/audit', [MonitoringController::class, 'audit']);

    // ---------- Monitoring → Efficiency (Reverted) Monitoring ----------
    Route::get('/monitoring/efficiency/detail', [MonitoringController::class, 'efficiencyDetail']);
    Route::get('/monitoring/efficiency', [MonitoringController::class, 'efficiency']);

    // ---------- Monitoring → Scan Monitoring ----------
    Route::get('/monitoring/scan/lookups', [MonitoringController::class, 'scanLookups']);
    Route::get('/monitoring/scan/by-da', [MonitoringController::class, 'scanByDa']);
    Route::get('/monitoring/scan/detail', [MonitoringController::class, 'scanDetail']);
    Route::get('/monitoring/scan', [MonitoringController::class, 'scanByClient']);


    // ---------- Monitoring → Collection Monitoring (PF / Return / EO) ----------
    Route::get('/monitoring/collection/lookups', [MonitoringController::class, 'collectionLookups']);
    Route::get('/monitoring/collection/values', [MonitoringController::class, 'collectionValues']);
    Route::get('/monitoring/collection', [MonitoringController::class, 'collectionMonitoring']);

    // ---------- Monitoring → Retainers Fee Monthly Monitoring ----------
    Route::get('/monitoring/retainers-fee/lookups', [MonitoringController::class, 'rfLookups']);
    Route::get('/monitoring/retainers-fee/forwarding', [MonitoringController::class, 'rfForwardingDetail']);
    Route::get('/monitoring/retainers-fee/non-billed', [MonitoringController::class, 'rfNonBilled']);
    Route::get('/monitoring/retainers-fee', [MonitoringController::class, 'rfBilled']);

    // ---------- Dashboard (CEO: everything · others: own numbers) ----------
    Route::get('/ims_dashboard', [IMSDashboardController::class, 'dashboard']);
    Route::get('/ims_dashboard/billing', [IMSDashboardController::class, 'dashboardBilling']);
    Route::get('/ims_dashboard/employees', [IMSDashboardController::class, 'dashboardEmployees']);
    Route::get('/ims_dashboard/clients', [IMSDashboardController::class, 'dashboardClients']);
    Route::get('/ims_dashboard/billing/detail', [IMSDashboardController::class, 'dashboardBillingDetail']);
    Route::get('/ims_dashboard/da', [IMSDashboardController::class, 'dashboardDa']);            // new
    Route::get('/ims_dashboard/auditor', [IMSDashboardController::class, 'dashboardAuditor']);  // new

    // ---------- HR settings ----------
    Route::get('/hr/lookups', [HR::class, 'lookups']);
    Route::post('/hr/shifts', [HR::class, 'saveShift']);
    Route::put('/hr/shifts/{id}', [HR::class, 'saveShift']);
    Route::put('/hr/shifts/{id}/default', [HR::class, 'setDefaultShift']);
    Route::post('/hr/night-diff', [HR::class, 'saveNightDiff']);
    Route::put('/hr/pay-rates/{id}', [HR::class, 'savePayRate']);
    Route::get('/hr/holidays', [HR::class, 'holidays']);
    Route::post('/hr/holidays', [HR::class, 'saveHoliday']);
    Route::delete('/hr/holidays/{id}', [HR::class, 'deleteHoliday']);
    Route::post('/hr/leave-types', [HR::class, 'saveLeaveType']);
    Route::put('/hr/leave-types/{id}', [HR::class, 'saveLeaveType']);
    Route::post('/hr/contribution-settings', [HR::class, 'saveContribution']);
    Route::get('/hr/contribution-preview', [HR::class, 'contributionPreview']);
    Route::get('/hr/withholding-tax', [HR::class, 'withholdingTax']);
    
    // ---------- Employee: shift, salary history, bank, leave credits ----------
    Route::get('/hr/employees', [HR::class, 'employees']);
    Route::get('/hr/employee-lookups', [HR::class, 'employeeLookups']);
    Route::get('/hr/places/{kind}', [HR::class, 'places'])->whereIn('kind', ['cities', 'barangays']);
    Route::post('/hr/employees', [HR::class, 'createEmployee']);
    Route::put('/hr/employees/{id}', [HR::class, 'updateEmployee']);
    Route::post('/hr/employees/{id}/photo', [HR::class, 'saveEmployeePhoto']);
    Route::get('/hr/employees/{id}', [HR::class, 'employee']);
    Route::put('/hr/employees/{id}/shift', [HR::class, 'setEmployeeShift']);
    Route::post('/hr/employees/{id}/salaries', [HR::class, 'addSalary']);
    Route::put('/hr/salaries/{id}/default', [HR::class, 'setDefaultSalary']);
    Route::post('/hr/employees/{id}/banks', [HR::class, 'saveBank']);
    Route::put('/hr/banks/{bankId}', [HR::class, 'updateBank']);
    Route::delete('/hr/banks/{bankId}', [HR::class, 'deleteBank']);
    Route::put('/hr/employees/{id}/leave-credits', [HR::class, 'saveLeaveCredit']);
    Route::post('/hr/leave-credits/generate', [HR::class, 'generateLeaveCredits']);
    
    // ---------- Attendance ----------
    Route::get('/hr/attendance', [HR::class, 'attendance']);
    Route::post('/hr/attendance', [HR::class, 'saveAttendance']);
    Route::get('/hr/attendance/today', [HR::class, 'myToday']);
    Route::post('/hr/attendance/punch', [HR::class, 'punch']);
    Route::post('/hr/attendance/recompute', [HR::class, 'recomputeAttendance']);
    
    // ---------- Biometric upload ----------
    Route::get('/hr/biometric/map', [HR::class, 'biometricMap']);
    Route::post('/hr/biometric/map', [HR::class, 'saveBiometricMap']);
    Route::delete('/hr/biometric/map/{id}', [HR::class, 'deleteBiometricMap']);
    Route::post('/hr/biometric/preview', [HR::class, 'biometricPreview']);
    Route::post('/hr/biometric/import', [HR::class, 'biometricImport']);
    
    // ---------- Overtime / Leave (approval) ----------
    Route::get('/hr/overtime', [HR::class, 'overtime']);
    Route::post('/hr/overtime', [HR::class, 'fileOvertime']);
    Route::put('/hr/overtime/{id}/{action}', [HR::class, 'decideOvertime'])->whereIn('action', ['approve', 'reject', 'cancel']);
    Route::get('/hr/leaves', [HR::class, 'leaves']);
    Route::post('/hr/leaves', [HR::class, 'fileLeave']);
    Route::put('/hr/leaves/{id}/{action}', [HR::class, 'decideLeave'])->whereIn('action', ['approve', 'reject', 'cancel']);
    
    // ---------- Payroll ----------
    Route::get('/hr/payroll', [HR::class, 'payrollRuns']);
    Route::post('/hr/payroll', [HR::class, 'createPayroll']);
    Route::get('/hr/payroll/items/{id}', [HR::class, 'payslip']);
    Route::get('/hr/payroll/{id}', [HR::class, 'payroll']);
    Route::post('/hr/payroll/{id}/compute', [HR::class, 'computePayroll']);
    Route::post('/hr/payroll/{id}/approve', [HR::class, 'approvePayroll']);
    Route::post('/hr/payroll/{id}/release', [HR::class, 'releasePayroll']);
    Route::post('/hr/payroll/{id}/cancel', [HR::class, 'cancelPayroll']);
    Route::post('/hr/payroll/{id}/undo-release', [HR::class, 'undoReleasePayroll']);
    Route::post('/hr/payroll/{id}/undo-approve', [HR::class, 'undoApprovePayroll']);
    Route::get('/hr/brackets/{type}', [HR::class, 'brackets'])->whereIn('type', ['sss', 'phic']);
    Route::post('/hr/brackets/{type}', [HR::class, 'saveBracket'])->whereIn('type', ['sss', 'phic']);
    Route::put('/hr/brackets/{type}/{id}', [HR::class, 'saveBracket'])->whereIn('type', ['sss', 'phic']);
    Route::get('/hr/payroll-cutoffs', [HR::class, 'payrollCutoffs']);
    Route::post('/hr/payroll-cutoffs', [HR::class, 'savePayrollCutoff']);
    Route::put('/hr/payroll-cutoffs/{id}', [HR::class, 'savePayrollCutoff']);
    Route::post('/hr/payroll/{id}/exclusions', [HR::class, 'savePayrollExclusion']);
    
    
    // ---------- Lending ----------
    Route::get('/lending/summary', [HR::class, 'lendingSummary']);
    Route::post('/lending/settings', [HR::class, 'saveLendingSetting']);
    Route::put('/lending/settings/{id}', [HR::class, 'saveLendingSetting']);
    Route::get('/lending/members', [HR::class, 'members']);
    Route::post('/lending/members', [HR::class, 'saveMember']);
    Route::put('/lending/members/{id}', [HR::class, 'saveMember']);
    Route::get('/lending/investments', [HR::class, 'investments']);
    Route::post('/lending/investments', [HR::class, 'invest']);
    Route::get('/lending/investments/{id}', [HR::class, 'investment']);
    Route::post('/lending/investments/{id}/top-up', [HR::class, 'topUp']);
    Route::post('/lending/investments/{id}/cashout', [HR::class, 'cashout']);
    Route::get('/lending/loans', [HR::class, 'loans']);
    Route::post('/lending/loans/preview', [HR::class, 'previewLoan']);
    Route::post('/lending/loans', [HR::class, 'applyLoan']);
    Route::get('/lending/loans/{id}', [HR::class, 'loan']);
    Route::put('/lending/loans/{id}/{action}', [HR::class, 'decideLoan'])->whereIn('action', ['approve', 'reject', 'cancel']);
    Route::post('/lending/loans/{id}/release', [HR::class, 'releaseLoan']);
    Route::post('/lending/loans/{id}/payments', [HR::class, 'payLoan']);
    
    // ---------- My profile (the logged-in staff) → ProfileController ----------
    // add at the top of routes/api.php:  use App\Http\Controllers\Api\ProfileController;
    Route::get('/hr/my-profile', [ProfileController::class, 'myProfile']);
    Route::put('/hr/my-profile', [ProfileController::class, 'updateMyProfile']);
    Route::get('/hr/my-requests', [ProfileController::class, 'myRequests']);
    Route::get('/hr/my-calendar', [ProfileController::class, 'myCalendar']);
    Route::put('/hr/my-requests/{kind}/{id}/cancel', [ProfileController::class, 'cancelMyRequest'])->whereIn('kind', ['overtime', 'leave']);

    Route::post('/hr/my-profile/photo', [ProfileController::class, 'saveMyPhoto']);
    Route::get('/hr/my-photo', [ProfileController::class, 'myPhoto']);

    Route::get('/hr/dashboard', [HRDashboardController::class, 'dashboard']);
    Route::get('/hr/birthdays-today', [ProfileController::class, 'birthdaysToday']);

    // ---------- VAT Summary ----------
    Route::get('/reports/vat-clients/lookups', [ReportController::class, 'vatClientsLookups']);
    Route::get('/reports/vat-clients', [ReportController::class, 'vatClientsSummary']);
    Route::get('/reports/vat-clients/branches', [ReportController::class, 'vatClientsBranches']);
    Route::get('/reports/vat-clients/amounts', [ReportController::class, 'vatClientsAmounts']);
    Route::get('/reports/vat-clients/remarks', [ReportController::class, 'vatClientsRemarks']);
    Route::put('/reports/vat-clients/remarks', [ReportController::class, 'vatClientsSaveRemarks']);
    Route::get('/reports/vat-clients/ebir', [ReportController::class, 'vatClientsEbir']);
    Route::get('/reports/vat-clients/ebir/details', [ReportController::class, 'vatClientsEbirDetails']);
    Route::post('/reports/vat-clients/undeclare', [ReportController::class, 'vatClientsUndeclare']);
    Route::post('/reports/vat-clients/undo', [ReportController::class, 'vatClientsUndo']);
    Route::post('/reports/vat-clients/approve', [ReportController::class, 'vatClientsApprove']);
    Route::get('/reports/vat-clients/undeclared', [ReportController::class, 'vatClientsUndeclared']);

    // Income Summary
    Route::get('/reports/income-clients', [ReportController::class, 'incomeClientsSummary']);
    Route::get('/reports/income-clients/branches', [ReportController::class, 'incomeClientsBranches']);
    Route::get('/reports/income-clients/statement', [ReportController::class, 'incomeClientsStatement']);

    // Percentage Tax Sum.  
    Route::get('/reports/pt-clients', [ReportController::class, 'ptClientsSummary']);
    Route::get('/reports/pt-clients/branches', [ReportController::class, 'ptClientsBranches']);

    // Expanded WHT
    Route::get('/reports/ewt-clients', [ReportController::class, 'ewtClientsSummary']);
    Route::get('/reports/ewt-clients/branches', [ReportController::class, 'ewtClientsBranches']);
    Route::get('/reports/ewt-clients/atc', [ReportController::class, 'ewtClientsAtc']);

    // Reverted Summary
    Route::get('/reports/reverted', [ReportController::class, 'revertedList']);
    Route::get('/reports/reverted/{type}/{invoiceNo}', [ReportController::class, 'revertedShow']);
    Route::post('/reports/reverted/{type}/{invoiceNo}/audit', [ReportController::class, 'revertedAudit']);
    Route::get('/reports/reverted/{type}/{invoiceNo}/attachment', [ReportController::class, 'revertedAttachment']);

    // Administration → Ticket
    Route::get('/admin/tickets', [AdministrationController::class, 'ticketList']);
    Route::get('/admin/tickets/{ticketNo}', [AdministrationController::class, 'ticketShow']);
    Route::get('/admin/tickets/{ticketNo}/attachment', [AdministrationController::class, 'ticketAttachment']);
    Route::post('/admin/tickets/{ticketNo}/approve', [AdministrationController::class, 'ticketApprove']);
    Route::post('/admin/tickets/{ticketNo}/decline', [AdministrationController::class, 'ticketDecline']);

    // Declared Month Settings
    Route::get('/admin/declared-months', [AdministrationController::class, 'declaredMonthAccounts']);
    Route::get('/admin/declared-months/{caid}', [AdministrationController::class, 'declaredMonthList']);
    Route::post('/admin/declared-months/{caid}', [AdministrationController::class, 'declaredMonthAdd']);
    Route::delete('/admin/declared-months/{caid}/{id}', [AdministrationController::class, 'declaredMonthRemove']);
    Route::get('/admin/declared-months', [AdministrationController::class, 'declaredMonthAccounts']);
    Route::post('/admin/declared-months', [AdministrationController::class, 'declaredMonthSet']);
    
    // User Logs
    Route::get('/admin/user-logs/lookups', [AdministrationController::class, 'userLogLookups']);
    Route::get('/admin/user-logs', [AdministrationController::class, 'userLogs']);
    Route::get('/admin/user-logs/{id}', [AdministrationController::class, 'userLogShow'])->whereNumber('id');

    // Administration → User Access (staff logins, Staff section)
    Route::get('/admin/user-access', [AdministrationController::class, 'userAccessList']);
    Route::get('/admin/user-access/mine', [AdministrationController::class, 'userAccessMine']);
    Route::get('/admin/user-access/{id}', [AdministrationController::class, 'userAccessShow'])->whereNumber('id');
    Route::put('/admin/user-access/{id}', [AdministrationController::class, 'userAccessSave'])->whereNumber('id');

    Route::get('/admin/database/files', [AdministrationController::class, 'databaseFiles']);
    Route::get('/admin/database/files/download', [AdministrationController::class, 'databaseDownload']);
    Route::post('/admin/database/backup', [AdministrationController::class, 'databaseBackup']);

    Route::get('/admin/user-access/{id}/clients', [AdministrationController::class, 'userAccessClients']);
    Route::post('/admin/user-access/{id}/clients/{caid}/login', [AdministrationController::class, 'userAccessGenerateLogin']);

    // Printing
    Route::get('/print/vat-summary', [PrintController::class, 'vatSummaryPrint']);
    Route::get('/print/esub', [PrintController::class, 'esubPrint']);
    Route::post('/print/income-tax-summary', [PrintController::class, 'incomeTaxSummaryPrepare']);
    Route::get('/print/income-tax-summary/{token}', [PrintController::class, 'incomeTaxSummaryPrint']);
    Route::get('/print/journal', [PrintController::class, 'journalPrint']);
    Route::post('/print/book-report', [PrintController::class, 'bookReportPrepare']);
    Route::get('/print/book-report/{token}', [PrintController::class, 'bookReportPrint']);
    Route::post('/print/ledger', [PrintController::class, 'ledgerPrepare']);
    Route::get('/print/ledger/{token}', [PrintController::class, 'ledgerPrint']);
    Route::post('/print/consolidated', [PrintController::class, 'consolidatedPrepare']);
    Route::get('/print/consolidated/{token}', [PrintController::class, 'consolidatedPrint']);
    Route::get('/print/trial-balance', [PrintController::class, 'trialBalancePrint']);
    Route::get('/print/working-trial-balance', [PrintController::class, 'workingTrialBalancePrint']);
    Route::post('/print/transactions', [PrintController::class, 'transactionsPrepare']);
    Route::get('/print/transactions/{token}', [PrintController::class, 'transactionsPrint']);
    Route::post('/print/vat-clients', [PrintController::class, 'vatClientsPrepare']);
    Route::get('/print/vat-clients/{token}', [PrintController::class, 'vatClientsPrint']);
    Route::get('/billing/soa/{transNumber}/print/{type}', [PrintController::class, 'billingSoaPrint']);
    Route::get('/billing/return-ar/{transNumber}/print', [PrintController::class, 'returnArPrint']);
    Route::get('/billing/deposit/r1', [PrintController::class, 'depositR1Print']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/read', [NotificationController::class, 'read']);
    Route::put('/notifications/read-all', [NotificationController::class, 'readAll']);    

    Route::get('/ai/messages', [AiAssistantController::class, 'index']);
    Route::post('/ai/messages', [AiAssistantController::class, 'store']);
    Route::delete('/ai/messages', [AiAssistantController::class, 'destroy']);

    require __DIR__ . '/api_messenger.php';
});

require __DIR__ . '/api_mobile.php';