<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function index(Request $request): JsonResponse
    {
        $stocks = Stock::query()
            ->with(['product:id,name,code,barcode,unit,min_stock_level,category', 'warehouse:id,name,code,type'])
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->warehouse_id))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->product_id))
            ->when(! $request->boolean('include_zero'), fn ($q) => $q->where('quantity', '!=', 0))
            ->when($request->boolean('low_stock'), fn ($q) => $q->whereHas('product',
                fn ($p) => $p->whereColumn('stocks.quantity', '<=', 'products.min_stock_level')))
            ->orderBy('warehouse_id')->orderBy('product_id')
            ->paginate(min(max((int) $request->input('per_page', 50), 1), 200));

        return response()->json($stocks);
    }

    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'reference' => ['nullable', 'string', 'max:255'],   // fatura / irsaliye no
            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->itemRules(),
        ]);

        $movements = $this->stock->receive(
            $request->user(), Warehouse::findOrFail($data['warehouse_id']), $data['items'], $data,
        );

        return response()->json(['message' => 'Stok girişi yapıldı.', 'movements' => $movements], 201);
    }

    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id', 'different:from_warehouse_id'],
            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->itemRules(),
        ]);

        $movements = $this->stock->transfer(
            $request->user(),
            Warehouse::findOrFail($data['from_warehouse_id']),
            Warehouse::findOrFail($data['to_warehouse_id']),
            $data['items'],
            $data,
        );

        return response()->json(['message' => 'Transfer yapıldı.', 'movements' => $movements], 201);
    }

    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'counted_quantity' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'note' => ['required', 'string', 'max:1000'], // düzeltme gerekçesi zorunlu
        ]);

        $movement = $this->stock->adjust(
            $request->user(), Warehouse::findOrFail($data['warehouse_id']),
            $data['product_id'], (float) $data['counted_quantity'], $data,
        );

        return response()->json([
            'message' => $movement ? 'Stok düzeltildi.' : 'Sayılan miktar sistemdekiyle aynı, değişiklik yapılmadı.',
            'movement' => $movement,
        ], $movement ? 201 : 200);
    }

    private function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'items.*.lot_number' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date' => ['nullable', 'date'],
        ];
    }
}
