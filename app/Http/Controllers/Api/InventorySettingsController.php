<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Task → Inventory → Settings
 *
 *   GET /inventory/settings   → { inventory_method }
 *   PUT /inventory/settings   { inventory_method: FIFO | WAC | SPECIFIC }
 *
 * Saved per client account (caid). Default is FIFO.
 */
class InventorySettingsController extends SalesController
{
    public const METHODS = ['FIFO', 'WAC', 'SPECIFIC'];
    private const TABLE = 'm_inventory_settings';

    private function caid(Request $request): int
    {
        $caid = $this->caidFor($request);
        abort_unless($caid, 422, 'No client is selected.');
        return (int) $caid;
    }

    /** current settings of the selected client (defaults when nothing is saved yet) */
    public static function forClient(int $caid): array
    {
        $row = DB::table(self::TABLE)->where('caid', $caid)->first();
        return [
            'inventory_method' => $row->inventory_method ?? 'FIFO',
        ];
    }

    public function showInvSettings(Request $request){
        return response()->json(self::forClient($this->caid($request)));
    }

    public function updateInvSettings(Request $request){
        $caid = $this->caid($request);
        $data = $request->validate([
            'inventory_method' => 'required|in:' . implode(',', self::METHODS),
        ]);

        $now = now();
        $exists = DB::table(self::TABLE)->where('caid', $caid)->exists();
        $values = [
            'inventory_method' => $data['inventory_method'],
            'updated_by' => optional($request->user())->id,
            'updated_at' => $now,
        ];
        if ($exists) {
            DB::table(self::TABLE)->where('caid', $caid)->update($values);
        } else {
            DB::table(self::TABLE)->insert($values + ['caid' => $caid, 'created_at' => $now]);
        }

        return response()->json(self::forClient($caid));
    }
}
