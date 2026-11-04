<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Warehouse::query()
                ->with(['users:id,name', 'hospital:id,name'])
                // Hastane konsinye lokasyonları yalnızca istenirse gelir
                ->when(! $request->boolean('include_consignment') && $request->type !== Warehouse::TYPE_CONSIGNMENT,
                    fn ($q) => $q->internal())
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
                ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
                ->orderBy('name')
                ->get()
        );
    }

    public function show(Warehouse $warehouse): JsonResponse
    {
        $warehouse->load(['users:id,name,email', 'stocks' => fn ($q) => $q->where('quantity', '!=', 0)->with('product:id,name,code,unit,min_stock_level')]);

        return response()->json($warehouse);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $warehouse = Warehouse::create($data);
        if (array_key_exists('user_ids', $data)) {
            $warehouse->users()->sync($data['user_ids']);
        }

        return response()->json($warehouse->load('users:id,name'), 201);
    }

    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        if ($warehouse->isConsignment()) {
            return $this->consignmentManagedElsewhere();
        }
        $data = $this->validated($request, $warehouse);
        $warehouse->update($data);
        if (array_key_exists('user_ids', $data)) {
            $warehouse->users()->sync($data['user_ids']);
        }

        return response()->json($warehouse->load('users:id,name'));
    }

    public function destroy(Warehouse $warehouse): JsonResponse
    {
        if ($warehouse->isConsignment()) {
            return $this->consignmentManagedElsewhere();
        }
        if (StockMovement::where('warehouse_id', $warehouse->id)->exists()) {
            return response()->json(['message' => 'Hareket görmüş depo silinemez; pasife alabilirsiniz.'], 422);
        }

        $warehouse->delete();

        return response()->json(['message' => 'Depo silindi.']);
    }

    private function consignmentManagedElsewhere(): JsonResponse
    {
        return response()->json(['message' => 'Konsinye lokasyonları ilgili hastanenin sayfasından yönetilir.'], 422);
    }

    private function validated(Request $request, ?Warehouse $warehouse = null): array
    {
        $req = $warehouse ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:255'],
            'code' => [$req, 'string', 'max:50', Rule::unique('warehouses', 'code')->ignore($warehouse?->id)],
            'type' => ['sometimes', Rule::in(Warehouse::TYPES)],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'user_ids' => ['sometimes', 'array'],          // bu depodan ürün düşebilecek personel
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);
    }
}
