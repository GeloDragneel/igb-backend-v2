<?php

use App\Http\Controllers\Api\MobileController;
use Illuminate\Support\Facades\Route;

/*
 * Mobile app endpoints → /api/mobile/<old_file_name>
 * Include from routes/api.php:   require __DIR__ . '/api_mobile.php';
 */
Route::prefix('mobile')->controller(MobileController::class)->group(function () {
    Route::get('check_table_structure', 'checkTableStructure');

    // bank
    Route::get('bank_account_list', 'bankAccountList');
    Route::get('bank_name_list', 'bankNameList');

    // billing
    Route::post('bill_payment', 'billPayment');
    Route::post('bill_collection', 'billCollection');
    Route::post('bill_deposit', 'billDeposit');

    // biometric
    Route::get('biometric_by_username', 'biometricByUsername');
    Route::post('biometric_registration', 'biometricRegistration');
    Route::get('biometric_validation', 'biometricValidation');

    // accounting
    Route::get('charts_expenses', 'chartsExpenses');
    Route::get('cash_disbursement_journal', 'cashDisbursementJournal');
    Route::get('bir2307', 'bir2307');

    // chat: rooms
    Route::post('chat_create_room', 'chatCreateRoom');
    Route::post('chat_rooms', 'chatRooms');
    Route::post('chat_messages', 'chatMessages');
    Route::post('chat_send_message', 'chatSendMessage');
    Route::post('chat_mark_read', 'chatMarkRead');

    // chat: direct
    Route::post('chat_get_conversations', 'chatGetConversations');
    Route::post('chat_get_messages', 'chatGetMessages');
    Route::post('chat_send_message_new', 'chatSendMessageNew');
    Route::post('get_user2_list', 'getUser2List');

    // chat debug (only when APP_DEBUG=true)
    Route::get('debug_chat_membership', 'debugChatMembership');
    Route::post('debug_send_message', 'debugSendMessage');
    Route::get('comprehensive_debug', 'comprehensiveDebug');

    // lists
    Route::get('cheque_type_list', 'chequeTypeList');
    Route::get('client_name_list', 'clientNameList');
    Route::get('client_account_list', 'clientAccountList');
    Route::get('documents', 'documents');
    Route::get('collection_list', 'collectionList');
    Route::get('deposit_list', 'depositList');

    // scans
    Route::get('expenses', 'expenses');
    Route::match(['get', 'delete'], 'delete_transaction', 'deleteTransaction');
    Route::get('view_scan', 'viewScan');                         // the scanned PDF (from S3)

    // expenses / reports
    Route::get('expense_report', 'expenseReport');
    Route::get('expenses_info', 'expensesInfo');
    Route::get('dashboard', 'dashboard');
    Route::get('general_ledger', 'generalLedger');
    Route::get('e_bir', 'eBir');

    // HR
    Route::get('employe_calendar', 'employeeCalendar');
    Route::get('employee_timeout', 'employeeTimeout');
    Route::get('leave_type_list', 'leaveTypeList');
    Route::get('leave_duration', 'leaveDuration');
    Route::post('leave_registration', 'leaveRegistration');
    Route::post('overtime_registration', 'overtimeRegistration');

    // login
    Route::post('login', 'login');

    // payments
    Route::get('payment_type_list', 'paymentTypeList');
    Route::get('leave_type_list_copy', 'paymentTypeList');      // old copy of payment_type_list
    Route::get('payment_col_type', 'paymentColType');
    Route::get('payment_receipt_type', 'paymentReceiptType');
    Route::get('payment_list', 'paymentList');
    Route::get('purchase_receipt_journal', 'purchaseReceiptJournal');

    // group chat
    Route::post('group_create', 'groupCreate');
    Route::post('group_add_members', 'groupAddMembers');
    Route::post('group_leave', 'groupLeave');
    Route::post('group_get_list', 'groupGetList');
    Route::post('group_get_members', 'groupGetMembers');
    Route::post('group_get_available_users', 'groupGetAvailableUsers');
    Route::post('group_get_messages', 'groupGetMessages');
    Route::post('group_send_message', 'groupSendMessage');

    // scans + uploads
    Route::get('sales', 'sales');
    Route::get('purchases', 'purchases');
    Route::post('upload_receipt', 'uploadReceipt');
    Route::post('upload_document', 'uploadDocument');

    // billing services
    Route::get('service_list', 'serviceList');
    Route::get('service_period', 'servicePeriod');

    // sales / purchases / reports
    Route::get('sales_journal', 'salesJournal');
    Route::get('sales_report', 'salesReport');
    Route::get('sales_info', 'salesInfo');
    Route::get('purchase_report', 'purchaseReport');
    Route::get('purchases_info', 'expensesInfo');               // same tables as expenses_info
    Route::get('trial_balance', 'trialBalance');
    Route::get('transaction', 'transaction');
});