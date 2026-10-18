<?php

namespace App\Http\Controllers\Api;

/** Expenses – all logic is in ApInvoiceController */
class ExpenseController extends ApInvoiceController
{
    protected string $apType        = 'Expenses';
    protected string $apScanType    = 'expenses';
    protected string $apFolder      = 'Expenses_Transaction';
    protected string $apModule      = 'Expenses/Transactions';
    protected array  $apTransferTo  = ['sales', 'purchases'];
    protected string $apDefaultItem = '^VARIOUS EXPENSES?[[:space:]-]*(VAT|VT)$';
}
