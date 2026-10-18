<?php

namespace App\Http\Controllers\Api;

/** Exportation – same structure as Importation; lines are zero-rated export sales */
class ExportationController extends TradeController
{
    protected array $cfg = [
        'master' => 't_exportation_master', 'detail' => 't_exportation_detail', 'fk' => 'exportation_master_id',
        'no' => 'exportation_no', 'party' => 'buyer_id', 'partyTable' => 'm_buyers',
        'date' => 'export_date', 'entry' => 'export_entry_no', 'country' => 'destination_country', 'pay' => 'payment_date',
        'prefix' => 'EXP-', 'folder' => 'Exportation', 'module' => 'Exportation / Sales',
        'inputs'  => ['quantity', 'fob_amount'],
        'amounts' => ['quantity', 'fob_amount', 'peso_amount'],
        'totals'  => ['total_fob_amount' => 'fob_amount', 'total_peso_amount' => 'peso_amount'],
    ];

    /** peso amount = FOB (invoice currency) × exchange rate */
    protected function calcLine(array $l, float $exRate): array
    {
        $fob = $this->r4($l['fob_amount'] ?? 0);
        return [
            'quantity'    => $this->r4($l['quantity'] ?? 0),
            'fob_amount'  => $fob,
            'peso_amount' => $this->r4($fob * $exRate),
        ];
    }

    /** Dr receivable / cash (total in PHP), Cr sales account per line (zero-rated) */
    protected function journalRows(object $m, $lines, $names, ?object $vatAccount): array
    {
        $rows = [[$m->account_code, $names[$m->account_code] ?? '', (float) $m->total_peso_amount, 0]];
        foreach ($lines as $l) {
            $rows[] = [$l->account_code ?: '0', $names[$l->account_code] ?? '', 0, (float) $l->peso_amount];
        }
        return $rows;
    }

    protected function listTotal(object $m): float
    {
        return (float) $m->total_peso_amount;
    }
}
