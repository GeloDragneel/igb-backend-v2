<?php

namespace App\Http\Controllers\Api;

use App\Models\BegBalanceProduct;
use App\Models\ItemClass;
use App\Models\Items;
use App\Models\PriceHistory;
use App\Models\ProductUnit;
use App\Models\SalesInvoiceDetail;
use App\Models\TaxType;
use App\Models\UnitType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Filesystem\FilesystemAdapter;
class ProductController extends SalesController
{
    /* ---------- list ---------- */

    /* GET /api/products */
    public function listProducts(Request $request)
    {
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json([]);

        $classes = ItemClass::pluck('name', 'id');
        $taxes   = TaxType::pluck('name', 'id');

        $rows = Items::where('added_by', $addedBy)->orderBy('item_code')->get();

        return response()->json($rows->map(fn ($i) => [
            'id'                       => $i->id,
            'item_code'                => $i->item_code,
            'description'              => $i->description,
            'upc_sku'                  => $i->upc_sku,
            'item_class_id'            => $i->item_class_id,
            'item_class'               => $classes[$i->item_class_id] ?? null,
            'tax_type_id'              => $i->tax_type_id,
            'tax_type'                 => $taxes[$i->tax_type_id] ?? null,
            'price'                    => (float) $i->price,
            'last_unit_cost'           => (float) $i->last_unit_cost,
            'equivalent_conversion'    => $i->equivalent_conversion,
            'gl_sales_account'         => $i->gl_sales_account,
            'gl_inventory_account'     => $i->gl_inventory_account,
            'gl_cost_of_sales_account' => $i->gl_cost_of_sales_account,
            'input_vat_account'        => $i->input_vat_account,
            'output_vat_account'       => $i->output_vat_account,
            'minimum_stock'            => $i->minimum_stock,
            'reorder_quantity'         => $i->reorder_quantity,
        ])->values());
    }

    /* GET /api/products-lookups  (dropdowns) */
    public function productLookups()
    {
        return response()->json([
            'item_classes' => ItemClass::orderBy('name')->get(['id', 'name']),
            'tax_types'    => TaxType::orderBy('name')->get(['id', 'name']),
            'unit_types'   => UnitType::orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function ownItem(Request $request, int $id): ?Items
    {
        return Items::where('id', $id)->where('added_by', $this->addedByFor($request))->first();
    }

    /* GET /api/products/{id}/units */
    public function productUnits(Request $request, int $id)
    {
        if (!$this->ownItem($request, $id)) return response()->json(['message' => 'Product not found.'], 404);

        return response()->json(
            ProductUnit::where('product_id', $id)->orderBy('id')
                ->get(['id', 'unit_type_id', 'description', 'price', 'cost'])
        );
    }

    /* GET /api/products/{id}/price-history */
    public function priceHistory(Request $request, int $id)
    {
        if (!$this->ownItem($request, $id)) return response()->json(['message' => 'Product not found.'], 404);

        return response()->json(
            PriceHistory::where('item_id', $id)->orderByDesc('id')->get(['price', 'created_at'])
                ->map(fn ($h) => ['price' => (float) $h->price, 'date' => $h->created_at?->format('Y-m-d H:i:s')])
        );
    }

    /* ---------- save ---------- */

    private function productRules(int $addedBy, ?int $id): array
    {
        return [
            'item_code'                => [
                'required', 'string', 'max:50',
                Rule::unique('m_items', 'item_code')->where('added_by', $addedBy)->ignore($id),
            ],
            'description'              => ['required', 'string', 'max:255'],
            'upc_sku'                  => ['nullable', 'string', 'max:100'],
            'item_class_id'            => ['required', 'integer', 'min:1'],
            'tax_type_id'              => ['nullable', 'integer'],
            'equivalent_conversion'    => ['nullable', 'numeric'],
            'gl_sales_account'         => ['nullable', 'string', 'max:20'],
            'gl_inventory_account'     => ['nullable', 'string', 'max:20'],
            'gl_cost_of_sales_account' => ['nullable', 'string', 'max:20'],
            'input_vat_account'        => ['nullable', 'string', 'max:20'],
            'output_vat_account'       => ['nullable', 'string', 'max:20'],
            'minimum_stock'            => ['nullable', 'numeric', 'min:0'],
            'reorder_quantity'         => ['nullable', 'numeric', 'min:0'],
            'units'                    => ['nullable', 'array'],
            'units.*.id'               => ['nullable', 'integer'],
            'units.*.unit_type_id'     => ['required', 'integer', 'min:1'],
            'units.*.value'            => ['nullable', 'numeric'],
            'units.*.price'            => ['nullable', 'numeric', 'min:0'],
            'units.*.cost'             => ['nullable', 'numeric', 'min:0'],
        ];
    }

    private function productMessages(): array
    {
        return [
            'item_code.required'             => 'Item ID is required',
            'item_code.unique'               => 'This Item ID already exists.',
            'description.required'           => 'Description is required',
            'item_class_id.required'         => 'Item Class is required',
            'item_class_id.min'              => 'Item Class is required',
            'units.*.unit_type_id.required'  => 'Choose a unit for every unit row.',
            'units.*.unit_type_id.min'       => 'Choose a unit for every unit row.',
        ];
    }

    /* POST /api/products            (multipart: data + image) */
    public function storeProduct(Request $request)
    {
        return $this->saveProduct($request, null);
    }

    /* POST /api/products/{id}       (multipart: data + image) */
    public function updateProduct(Request $request, int $id)
    {
        return $this->saveProduct($request, $id);
    }

    private function saveProduct(Request $request, ?int $id)
    {
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json(['message' => 'No client account selected.'], 422);

        $request->validate([
            'data'  => ['required', 'json'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        $d = Validator::make(
            json_decode($request->input('data'), true) ?? [],
            $this->productRules($addedBy, $id),
            $this->productMessages()
        )->validate();

        $item = $id ? $this->ownItem($request, $id) : new Items();
        if (!$item) return response()->json(['message' => 'Product not found.'], 404);

        $units = collect($d['units'] ?? []);

        (new Items)->getConnection()->transaction(function () use ($item, $d, $units, $addedBy) {
            $item->forceFill([
                'item_code'                => $d['item_code'],
                'description'              => $d['description'],
                'upc_sku'                  => $d['upc_sku'] ?? null,
                'item_class_id'            => $d['item_class_id'],
                'tax_type_id'              => $d['tax_type_id'] ?? null,
                'equivalent_conversion'    => $d['equivalent_conversion'] ?? null,
                'gl_sales_account'         => $d['gl_sales_account'] ?? null,
                'gl_inventory_account'     => $d['gl_inventory_account'] ?? null,
                'gl_cost_of_sales_account' => $d['gl_cost_of_sales_account'] ?? null,
                'input_vat_account'        => $d['input_vat_account'] ?? null,
                'output_vat_account'       => $d['output_vat_account'] ?? null,
                'minimum_stock'            => $d['minimum_stock'] ?? 0,
                'reorder_quantity'         => $d['reorder_quantity'] ?? 0,
                // first unit = default unit / price used on invoices
                'unit_type_id'             => $units->first()['unit_type_id'] ?? $item->unit_type_id,
                'price'                    => $units->first()['price'] ?? ($item->price ?? 0),
                'last_unit_cost'           => $units->first()['cost'] ?? ($item->last_unit_cost ?? 0),
                'is_active'                => 1,
                'added_by'                 => $addedBy,
            ])->save();

            // units: update / add, and remove the ones deleted in the popup
            $keep = [];
            foreach ($units as $row) {
                $unit = !empty($row['id'])
                    ? ProductUnit::where('id', $row['id'])->where('product_id', $item->id)->first()
                    : null;
                $oldPrice = $unit ? (float) $unit->price : null;
                $unit ??= new ProductUnit();

                $unit->forceFill([
                    'product_id'   => $item->id,
                    'user_id'      => $addedBy,
                    'unit_type_id' => $row['unit_type_id'],
                    'description'  => $row['value'] ?? null,
                    'price'        => $row['price'] ?? 0,
                    'cost'         => $row['cost'] ?? 0,
                ])->save();
                $keep[] = $unit->id;

                // price history: new unit or price changed (old SavePriceHistory)
                $newPrice = (float) ($row['price'] ?? 0);
                if ($oldPrice === null || abs($oldPrice - $newPrice) > 0.00001) {
                    PriceHistory::create(['item_id' => $item->id, 'price' => $newPrice]);
                }
            }
            ProductUnit::where('product_id', $item->id)->whereNotIn('id', $keep ?: [0])->delete();
        });

        // image (old Product_Image/{UserID}_{ID}.webp)
        $imageError = null;
        if ($request->hasFile('image')) {
            try {
                Storage::disk('s3')->putFileAs('Product_Image', $request->file('image'), "{$addedBy}_{$item->id}");
            } catch (\Throwable $e) {
                report($e);
                $imageError = 'Product saved, but the image upload failed.';
            }
        }

        return response()->json([
            'message'     => $id ? 'Product successfully updated' : 'Product successfully saved',
            'id'          => $item->id,
            'image_error' => $imageError,
        ]);
    }

    /* DELETE /api/products/{id} */
    public function destroyProduct(Request $request, int $id)
    {
        $addedBy = $this->addedByFor($request);
        $item = $this->ownItem($request, $id);
        if (!$item) return response()->json(['message' => 'Product not found.'], 404);

        if (SalesInvoiceDetail::where('added_by', $addedBy)->where('item_id', $id)->exists()) {
            return response()->json(['message' => "{$item->item_code} is used in invoices. It can't be deleted."], 422);
        }

        (new Items)->getConnection()->transaction(function () use ($item) {
            ProductUnit::where('product_id', $item->id)->delete();
            $item->delete();
        });

        try {
            Storage::disk('s3')->delete(["Product_Image/{$addedBy}_{$id}", "Product_Image/{$addedBy}_{$id}.webp"]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => 'Product successfully deleted']);
    }

    /* GET /api/products/{id}/image */
    public function productImage(Request $request, int $id)
    {
        $addedBy = $this->addedByFor($request);
        if (!$this->ownItem($request, $id)) return response()->json(['message' => 'Product not found.'], 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('s3');

        foreach (["Product_Image/{$addedBy}_{$id}", "Product_Image/{$addedBy}_{$id}.webp"] as $key) {
            if ($disk->exists($key)) return $disk->response($key);
        }
        return response()->json(['message' => 'No image'], 404);
    }

    /* ---------- beginning balances ---------- */

    /* GET /api/products/beginning?from=YYYY-MM-DD&to=YYYY-MM-DD */
    public function begBalances(Request $request)
    {
        $d = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date'],
        ]);
        $addedBy = $this->addedByFor($request);
        $period  = "{$d['from']} to {$d['to']}";

        $begs = BegBalanceProduct::where('added_by', $addedBy)
            ->where('account_period', $period)
            ->get()
            ->keyBy('item_id');

        $rows = Items::where('added_by', $addedBy)->orderBy('item_code')->get(['id', 'item_code', 'description']);

        return response()->json($rows->map(fn ($i) => [
            'item_id'     => $i->id,
            'item_code'   => $i->item_code,
            'description' => $i->description,
            'beg_id'      => $begs[$i->id]->id ?? null,
            'quantity'    => (float) ($begs[$i->id]->quantity ?? 0),
            'unit_cost'   => (float) ($begs[$i->id]->unit_cost ?? 0),
            'total_cost'  => (float) ($begs[$i->id]->total_cost ?? 0),
        ])->values());
    }

    /* POST /api/products/beginning */
    public function saveBegBalance(Request $request)
    {
        $d = $request->validate([
            'item_id'   => ['required', 'integer'],
            'quantity'  => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'from'      => ['required', 'date'],
            'to'        => ['required', 'date'],
        ]);
        $addedBy = $this->addedByFor($request);

        if (!$this->ownItem($request, (int) $d['item_id'])) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $period = "{$d['from']} to {$d['to']}";
        BegBalanceProduct::updateOrCreate(
            ['item_id' => $d['item_id'], 'added_by' => $addedBy, 'account_period' => $period],
            [
                'quantity'       => $d['quantity'],
                'unit_cost'      => $d['unit_cost'],
                'total_cost'     => $this->r4($d['quantity'] * $d['unit_cost']),
                'declared_month' => substr($d['from'], 0, 7),
            ]
        );

        return response()->json(['message' => 'Beginning balance saved']);
    }
}