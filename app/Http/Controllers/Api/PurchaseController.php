<?php

namespace App\Http\Controllers\Api;

/** Purchases / Vendor Invoices (old Vendor_Invoices.php) – all logic is in ApInvoiceController */
class PurchaseController extends ApInvoiceController
{
    protected string $apType        = 'Purchases';
    protected string $apScanType    = 'purchases';
    protected string $apFolder      = 'Vendor_Invoices';
    protected string $apModule      = 'Purchases/Vendor Invoices';
    protected array  $apTransferTo  = ['sales', 'expenses'];
    protected string $apDefaultItem = '^VARIOUS PURCHASES?[[:space:]-]*(VAT|VT)$';
}
