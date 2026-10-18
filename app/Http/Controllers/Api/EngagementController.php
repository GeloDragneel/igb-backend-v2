<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Setup → Import Engagement (sellers, m_sellers) and Export Engagement (buyers, m_buyers)
 * Routes: /api/engagements/{type}   type = import | export
 */
class EngagementController extends SalesController
{
    private const MAP = [
        'import' => ['table' => 'm_sellers', 'used_in' => 't_importation_master', 'fk' => 'seller_id', 'label' => 'Seller'],
        'export' => ['table' => 'm_buyers',  'used_in' => 't_exportation_master', 'fk' => 'buyer_id',  'label' => 'Buyer'],
    ];

    private function cfg(string $type): array
    {
        abort_unless(isset(self::MAP[$type]), 404, 'Unknown engagement type.');
        return self::MAP[$type];
    }

    /* GET /api/engagements/{type} */
    public function engIndex(Request $request, string $type)
    {
        $c = $this->cfg($type);
        $by = $this->addedByFor($request);
        if (!$by) return response()->json([]);

        $used = DB::table($c['used_in'])->where('added_by', $by)->groupBy($c['fk'])
            ->selectRaw("{$c['fk']} AS id, COUNT(*) AS n")->pluck('n', 'id');

        $rows = DB::table($c['table'])->where('added_by', $by)->orderBy('name')
            ->get(['id', 'name', 'address', 'tin', 'created_at']);

        return response()->json($rows->map(fn ($r) => (array) $r + ['used' => (int) ($used[$r->id] ?? 0)]));
    }

    private function rules(array $c, int $by, ?int $id = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150',
                Rule::unique($c['table'], 'name')->where(fn ($q) => $q->where('added_by', $by))->ignore($id)],
            'address' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:20'],
        ];
    }

    /* POST /api/engagements/{type} */
    public function engStore(Request $request, string $type)
    {
        $c = $this->cfg($type);
        $by = $this->addedByFor($request);
        if (!$by) return response()->json(['message' => 'No client account selected.'], 422);
        $d = $request->validate($this->rules($c, $by), ['name.unique' => "{$c['label']} already exists.", 'name.required' => "{$c['label']} name is required."]);

        $id = DB::table($c['table'])->insertGetId([
            'name' => trim($d['name']), 'address' => $d['address'] ?? null, 'tin' => $d['tin'] ?? null,
            'added_by' => $by, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'message' => "{$c['label']} saved"]);
    }

    /* PUT /api/engagements/{type}/{id} */
    public function engUpdate(Request $request, string $type, int $id)
    {
        $c = $this->cfg($type);
        $by = $this->addedByFor($request);
        if (!DB::table($c['table'])->where('added_by', $by)->where('id', $id)->exists()) {
            return response()->json(['message' => "{$c['label']} not found."], 404);
        }
        $d = $request->validate($this->rules($c, $by, $id), ['name.unique' => "{$c['label']} already exists.", 'name.required' => "{$c['label']} name is required."]);

        DB::table($c['table'])->where('id', $id)->update([
            'name' => trim($d['name']), 'address' => $d['address'] ?? null, 'tin' => $d['tin'] ?? null, 'updated_at' => now(),
        ]);
        return response()->json(['message' => "{$c['label']} saved"]);
    }

    /* DELETE /api/engagements/{type}/{id}   (blocked while used) */
    public function engDestroy(Request $request, string $type, int $id)
    {
        $c = $this->cfg($type);
        $by = $this->addedByFor($request);
        if (!DB::table($c['table'])->where('added_by', $by)->where('id', $id)->exists()) {
            return response()->json(['message' => "{$c['label']} not found."], 404);
        }
        $n = DB::table($c['used_in'])->where($c['fk'], $id)->count();
        if ($n) return response()->json(['message' => "Can't delete: used in {$n} " . ($type === 'import' ? 'importation' : 'exportation') . ($n === 1 ? '' : 's') . '.'], 422);

        DB::table($c['table'])->where('id', $id)->delete();
        return response()->json(['message' => "{$c['label']} deleted"]);
    }
}