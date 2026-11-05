<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Support\CapturedLocation;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saha personelinin mobil uygulamasının kullandığı uç noktalar.
 */
class MobileController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    /** Personelin ürün düşebileceği depolar */
    public function warehouses(Request $request): JsonResponse
    {
        return response()->json($this->allowedWarehouses($request->user())->get(['id', 'name', 'code', 'type']));
    }

    /** Bir depodaki güncel stok (personelin çantası/aracı) */
    public function warehouseStock(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request->user(), $warehouse);

        $stocks = Stock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->with('product:id,name,code,barcode,gtin,tracking,unit,category')
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->get()
            ->sortBy('product.name') // ürün adına göre; aynı üründe SKT'si yakın lot önce (sıralama kararlı)
            ->values();

        return response()->json(['warehouse' => $warehouse->only(['id', 'name', 'code', 'type']), 'stocks' => $stocks]);
    }

    /**
     * Stoktan ürün düşme. Mobil uygulama her gönderim için bir
     * "Idempotency-Key" header'ı (UUID) üretmeli; bağlantı kopup aynı istek
     * tekrar gönderilirse stok ikinci kez düşülmez.
     */
    public function storeUsage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            // Ürünün verildiği hastane (mümessil teslimatı). Pasif hastane seçilemez.
            'hospital_id' => ['nullable', 'integer', Rule::exists('hospitals', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'reference' => ['nullable', 'string', 'max:255'],   // hasta / kurum / protokol no
            'note' => ['nullable', 'string', 'max:1000'],
            ...CapturedLocation::rules(),
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            // Belirli bir lot/seri hedeflemek için; verilmezse SKT'si en yakın lottan düşülür
            'items.*.stock_id' => ['nullable', 'integer'],
            'items.*.lot_number' => ['nullable', 'string', 'max:100'],
            'items.*.serial_number' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
        ], CapturedLocation::messages());
        $data = [...$data, ...CapturedLocation::attributes($data)];

        $key = $request->header('Idempotency-Key');
        if ($key !== null && (strlen($key) < 8 || strlen($key) > 100)) {
            return response()->json(['message' => 'Idempotency-Key 8-100 karakter olmalı.'], 422);
        }

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $this->authorizeWarehouse($request->user(), $warehouse);

        $result = $this->stock->recordUsage($request->user(), $warehouse, $data['items'], $data, $key);
        $result['movements'] = EloquentCollection::make($result['movements'])->loadMissing('hospital:id,name');

        return response()->json([
            'message' => $result['replayed'] ? 'Bu kayıt daha önce alınmıştı.' : 'Kayıt alındı, stoktan düşüldü.',
            'batch_uuid' => $result['batch_uuid'],
            'movements' => $result['movements'],
        ], $result['replayed'] ? 200 : 201);
    }

    /** Teslimat yapılabilecek aktif hastaneler */
    public function hospitals(Request $request): JsonResponse
    {
        return response()->json(
            Hospital::query()
                ->where('is_active', true)
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', '%'.$request->q.'%')
                    ->orWhere('city', 'like', '%'.$request->q.'%')
                    ->orWhere('district', 'like', '%'.$request->q.'%')))
                ->orderBy('name')
                ->get(['id', 'name', 'city', 'district'])
        );
    }

    /** Personelin kendi kullanım geçmişi */
    public function myUsages(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json(
            StockMovement::query()
                ->where('user_id', $request->user()->id)
                ->where('type', StockMovement::TYPE_USAGE)
                ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
                ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
                ->when($request->filled('hospital_id'), fn ($q) => $q->where('hospital_id', $request->hospital_id))
                ->with(['product:id,name,code,unit', 'warehouse:id,name', 'hospital:id,name'])
                ->latest('id')
                ->paginate(min(max((int) $request->input('per_page', 25), 1), 100))
        );
    }

    private function allowedWarehouses(User $user)
    {
        $query = Warehouse::query()->internal()->where('is_active', true)->orderBy('name');

        return $user->isSuperAdmin() ? $query : $query->whereHas('users', fn ($q) => $q->whereKey($user->id));
    }

    private function authorizeWarehouse(User $user, Warehouse $warehouse): void
    {
        if (! $this->allowedWarehouses($user)->whereKey($warehouse->id)->exists()) {
            abort(response()->json(['message' => 'Bu depodan işlem yapma yetkiniz yok.'], 403));
        }
    }
}
