<?php

namespace App\Http\Controllers\Api;

use App\Models\ChartsOfAccount;
use App\Models\SalesInvoiceDetail;
use App\Models\SalesInvoiceMaster;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChartOfAccountController extends SalesController
{
    /* root_name 0 / empty = top level */
    private function isTop($root): bool
    {
        return $root === null || $root === '' || (string) $root === '0';
    }

    /* GET /api/accounts */
    public function listAccounts(Request $request)
    {
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json([]);

        $rows = ChartsOfAccount::where('added_by', $addedBy)->orderBy('account_code')->get();

        // codes that have sub-accounts
        $hasKids = $rows->pluck('root_name')
            ->reject(fn ($r) => $this->isTop($r))
            ->map(fn ($r) => (string) $r)
            ->flip();

        return response()->json($rows->map(fn ($a) => [
            'id'           => $a->id,
            'code'         => (string) $a->account_code,
            'name'         => $a->account_name,
            'parent'       => $this->isTop($a->root_name) ? null : (string) $a->root_name,
            'type'         => $a->account_type,
            'description'  => $a->description,
            'is_for_paid'  => (bool) $a->is_for_paid,
            'is_sales'     => (bool) $a->is_sales,
            'is_purchases' => (bool) $a->is_purchases,
            'is_expenses'  => (bool) $a->is_expenses,
            'isGroup'      => $this->isTop($a->root_name) || $hasKids->has((string) $a->account_code),
        ])->values());
    }

    private function accountRules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:150'],
            'parent'       => ['nullable', 'string', 'max:20'],
            'type'         => ['required', 'string', 'max:50'],
            'description'  => ['nullable', 'string', 'max:255'],
            'is_for_paid'  => ['boolean'],
            'is_sales'     => ['boolean'],
            'is_purchases' => ['boolean'],
            'is_expenses'  => ['boolean'],
        ];
    }

    private function parentExists(int $addedBy, ?string $parent): bool
    {
        return !$parent || ChartsOfAccount::where('added_by', $addedBy)->where('account_code', $parent)->exists();
    }

    /* POST /api/accounts */
    public function storeAccount(Request $request)
    {
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json(['message' => 'No client account selected.'], 422);

        $d = $request->validate([
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+$/',
                Rule::unique('m_charts_of_account', 'account_code')->where('added_by', $addedBy),
            ],
        ] + $this->accountRules(), [
            'code.unique' => 'This account code already exists.',
            'code.regex'  => 'Use numbers, letters, dots or dashes only.',
        ]);

        if (!$this->parentExists($addedBy, $d['parent'] ?? null)) {
            return response()->json(['message' => 'Parent account not found.'], 422);
        }

        ChartsOfAccount::create([
            'account_code' => $d['code'],
            'account_name' => $d['name'],
            'root_name'    => $d['parent'] ?? null ?: 0,
            'account_type' => $d['type'],
            'description'  => $d['description'] ?? null,
            'is_for_paid'  => $d['is_for_paid'] ?? false,
            'is_sales'     => $d['is_sales'] ?? false,
            'is_purchases' => $d['is_purchases'] ?? false,
            'is_expenses'  => $d['is_expenses'] ?? false,
            'added_by'     => $addedBy,
        ]);

        return response()->json(['message' => "{$d['code']} – {$d['name']} added"]);
    }

    /* PUT /api/accounts/{code}  (code can't change) */
    public function updateAccount(Request $request, string $code)
    {
        $addedBy = $this->addedByFor($request);
        $acc = ChartsOfAccount::where('added_by', $addedBy)->where('account_code', $code)->first();
        if (!$acc) return response()->json(['message' => 'Account not found.'], 404);

        $d = $request->validate($this->accountRules());
        $parent = $d['parent'] ?? null;

        if (!$this->parentExists($addedBy, $parent)) {
            return response()->json(['message' => 'Parent account not found.'], 422);
        }

        // parent can't be itself or one of its own sub-accounts
        $p = $parent;
        while ($p) {
            if ((string) $p === (string) $code) {
                return response()->json(['message' => "An account can't be moved under itself."], 422);
            }
            $root = ChartsOfAccount::where('added_by', $addedBy)->where('account_code', $p)->value('root_name');
            $p = $this->isTop($root) ? null : (string) $root;
        }

        $acc->update([
            'account_name' => $d['name'],
            'root_name'    => $parent ?: 0,
            'account_type' => $d['type'],
            'description'  => $d['description'] ?? null,
            'is_for_paid'  => $d['is_for_paid'] ?? false,
            'is_sales'     => $d['is_sales'] ?? false,
            'is_purchases' => $d['is_purchases'] ?? false,
            'is_expenses'  => $d['is_expenses'] ?? false,
        ]);

        return response()->json(['message' => "{$code} updated"]);
    }

    /* DELETE /api/accounts/{code} */
    public function destroyAccount(Request $request, string $code)
    {
        $addedBy = $this->addedByFor($request);
        $acc = ChartsOfAccount::where('added_by', $addedBy)->where('account_code', $code)->first();
        if (!$acc) return response()->json(['message' => 'Account not found.'], 404);

        if (ChartsOfAccount::where('added_by', $addedBy)->where('root_name', $code)->exists()) {
            return response()->json(['message' => "{$code} has sub-accounts. Move or delete them first."], 422);
        }

        $used = SalesInvoiceMaster::where('added_by', $addedBy)->where('account_code', $code)->count()
              + SalesInvoiceDetail::where('added_by', $addedBy)->where('account_code', $code)->distinct('invoice_no')->count('invoice_no');
        if ($used > 0) {
            return response()->json(['message' => "{$code} is used in {$used} invoice(s). It can't be deleted."], 422);
        }

        $acc->delete();
        return response()->json(['message' => "{$code} deleted"]);
    }
}