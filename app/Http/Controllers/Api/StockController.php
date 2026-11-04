<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StockController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Stok satırları (lot/seri/SKT bazında).
     * Filtreler: warehouse_id, product_id, q (ürün adı/kodu, lot, seri),
     * expiry=expired|critical|warning (SKT'si geçmiş / kritik / yaklaşan), include_zero
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['expiry' => ['nullable', 'in:expired,critical,warning']]);
        $today = Carbon::today();

        $stocks = Stock::query()
            ->with(['product:id,name,code,barcode,gtin,tracking,unit,min_stock_level,category', 'warehouse:id,name,code,type'])
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->warehouse_id))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->product_id))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('lot_number', 'like', '%'.$request->q.'%')
                ->orWhere('serial_number', 'like', '%'.$request->q.'%')
                ->orWhereHas('product', fn ($p) => $p
                    ->where('name', 'like', '%'.$request->q.'%')
                    ->orWhere('code', 'like', '%'.$request->q.'%'))))
            ->when(! $request->boolean('include_zero'), fn ($q) => $q->where('quantity', '!=', 0))
            ->when($request->expiry === 'expired', fn ($q) => $q->whereDate('expiry_date', '<', $today))
            ->when($request->expiry === 'critical', fn ($q) => $q->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', $today->copy()->addDays(config('inventory.expiry_critical_days'))))
            ->when($request->expiry === 'warning', fn ($q) => $q->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', $today->copy()->addDays(config('inventory.expiry_warning_days'))))
            ->when($request->filled('expiry'),
                fn ($q) => $q->orderBy('expiry_date'),
                fn ($q) => $q->orderBy('warehouse_id')->orderBy('product_id')
                    ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_date'))
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

    /** Lot/seri verilmeyen satırlarda SKT'si en yakın lotlardan otomatik düşülür. */
    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id', 'different:from_warehouse_id'],
            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->itemRules(),
            'items.*.stock_id' => ['nullable', 'integer'],
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

    /**
     * Sayım düzeltmesi. Hangi lot/seri satırının sayıldığı stock_id ile
     * (ya da lot_number / serial_number ile) belirtilir.
     */
    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'stock_id' => ['nullable', 'integer', 'exists:stocks,id'],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'expiry_date' => ['nullable', 'date'],
            'counted_quantity' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'note' => ['required', 'string', 'max:1000'], // düzeltme gerekçesi zorunlu
        ]);

        $movement = $this->stock->adjust(
            $request->user(), Warehouse::findOrFail($data['warehouse_id']),
            $data['product_id'], (float) $data['counted_quantity'], $data,
            collect($data)->only(['stock_id', 'lot_number', 'serial_number', 'expiry_date'])->all(),
        );

        return response()->json([
            'message' => $movement ? 'Stok düzeltildi.' : 'Sayılan miktar sistemdekiyle aynı, değişiklik yapılmadı.',
            'movement' => $movement,
        ], $movement ? 201 : 200);
    }

    private function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'items.*.lot_number' => ['nullable', 'string', 'max:100'],
            'items.*.serial_number' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date' => ['nullable', 'date'],
        ];
    }
}
