<?php

namespace App\Http\Controllers\Api;

/** Importation (old Importation page: tbl2_importation_master / _detail) */
class ImportationController extends TradeController
{
    protected array $cfg = [
        'master' => 't_importation_master', 'detail' => 't_importation_detail', 'fk' => 'importation_master_id',
        'no' => 'importation_no', 'party' => 'seller_id', 'partyTable' => 'm_sellers',
        'date' => 'release_date', 'entry' => 'import_entry_no', 'country' => 'origin_country', 'pay' => 'vat_payment_date',
        'prefix' => 'IMP-', 'folder' => 'Importation', 'module' => 'Importation / Purchase Order',
        'inputs'  => ['total_landed_cost', 'dutiable_value', 'exempt_imports'],
        'amounts' => ['total_landed_cost', 'charges_from_custom', 'dutiable_value', 'amount_of_vat', 'taxable_imports', 'exempt_imports'],
        'totals'  => [
            'total_landed_cost' => 'total_landed_cost', 'total_charge_before' => 'charges_from_custom',
            'total_dutiable_value' => 'dutiable_value', 'total_vat' => 'amount_of_vat',
            'total_taxable_imports' => 'taxable_imports', 'total_exempt_imports' => 'exempt_imports',
        ],
    ];

    /** old CalculateNumber(): charges = landed − dutiable, taxable = landed − exempt, VAT = taxable × 12% */
    protected function calcLine(array $l, float $exRate): array
    {
        $landed   = $this->r4($l['total_landed_cost'] ?? 0);
        $dutiable = $this->r4($l['dutiable_value'] ?? 0);
        $exempt   = $this->r4($l['exempt_imports'] ?? 0);
        $taxable  = $this->r4($landed - $exempt);

        return [
            'total_landed_cost'   => $landed,
            'charges_from_custom' => $this->r4($landed - $dutiable),
            'dutiable_value'      => $dutiable,
            'amount_of_vat'       => $this->r4($taxable * 0.12),
            'taxable_imports'     => $taxable,
            'exempt_imports'      => $exempt,
        ];
    }

    /** old doGetJournalList: Cr Accounts (VAT + taxable), Dr Input VAT, Dr line account (taxable imports) */
    protected function journalRows(object $m, $lines, $names, ?object $vatAccount): array
    {
        $rows = [[$m->account_code, $names[$m->account_code] ?? '', 0, (float) $m->total_vat + (float) $m->total_taxable_imports]];
        foreach ($lines as $l) {
            if ((float) $l->amount_of_vat != 0) {
                $rows[] = [$vatAccount->account_code ?? '0', $vatAccount->account_name ?? 'Input VAT', (float) $l->amount_of_vat, 0];
            }
            $rows[] = [$l->account_code ?: '0', $names[$l->account_code] ?? '', (float) $l->taxable_imports, 0];
        }
        return $rows;
    }

    protected function listTotal(object $m): float
    {
        return (float) $m->total_landed_cost;
    }
}
