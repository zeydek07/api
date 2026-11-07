<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Hospital;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HospitalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $since = now()->subDays(30)->startOfDay();

        $hospitals = Hospital::query()
            ->select('hospitals.*')
            // Hastanedeki konsinye ürün adedi (tüm lokasyonlar)
            ->addSelect(['consignment_units' => Stock::query()
                ->selectRaw('COALESCE(SUM(stocks.quantity), 0)')
                ->join('warehouses', 'warehouses.id', '=', 'stocks.warehouse_id')
                ->whereColumn('warehouses.hospital_id', 'hospitals.id')
                ->where('warehouses.type', Warehouse::TYPE_CONSIGNMENT)
                ->where('stocks.quantity', '>', 0)])
            ->with('responsible:id,name')
            // Son 30 günde bu hastaneye yapılan konsinye teslimat belgesi sayısı ve son teslimat
            ->withCount(['deliveries as deliveries_30d' => fn ($q) => $q
                ->where('type', Delivery::TYPE_DELIVERY)->where('status', Delivery::STATUS_COMPLETED)
                ->where('delivered_at', '>=', $since)])
            ->withMax(['deliveries as last_delivery_at' => fn ($q) => $q
                ->where('type', Delivery::TYPE_DELIVERY)->where('status', Delivery::STATUS_COMPLETED)], 'delivered_at')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->q.'%')
                ->orWhere('code', 'like', '%'.$request->q.'%')
                ->orWhere('district', 'like', '%'.$request->q.'%')))
            ->when($request->filled('city'), fn ($q) => $q->where('city', $request->city))
            ->when($request->filled('responsible_user_id'), fn ($q) => $q->where('responsible_user_id', $request->responsible_user_id))
            ->when($request->boolean('mine'), fn ($q) => $q->where('responsible_user_id', $request->user()->id))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json($hospitals);
    }

    public function cities(): JsonResponse
    {
        return response()->json(Hospital::whereNotNull('city')->distinct()->orderBy('city')->pluck('city'));
    }

    /**
     * Hastane kartı: bilgiler, ürün bazında toplam teslimat ve son teslimatlar.
     * Yetkiye göre yalnızca kendi ekibinin teslimatlarını görür.
     */
    public function show(Request $request, Hospital $hospital): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = $request->filled('from') ? $request->date('from')->startOfDay() : now()->subDays(89)->startOfDay();
        $to = $request->filled('to') ? $request->date('to')->endOfDay() : now()->endOfDay();

        $base = fn () => MovementController::scoped($request->user())
            ->where('stock_movements.hospital_id', $hospital->id)
            ->where('stock_movements.type', StockMovement::TYPE_USAGE)
            ->whereBetween('stock_movements.created_at', [$from, $to]);

        $products = $base()->toBase()
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->groupBy('products.id', 'products.name', 'products.code', 'products.unit')
            ->select('products.id', 'products.name', 'products.code', 'products.unit')
            ->selectRaw('SUM(-stock_movements.quantity) as total_quantity')
            ->selectRaw('COUNT(*) as line_count')
            ->orderByDesc('total_quantity')
            ->get();

        $reps = $base()->toBase()
            ->join('users', 'users.id', '=', 'stock_movements.user_id')
            ->groupBy('users.id', 'users.name')
            ->select('users.id', 'users.name')
            ->selectRaw('COUNT(DISTINCT stock_movements.batch_uuid) as delivery_count')
            ->selectRaw('MAX(stock_movements.created_at) as last_at')
            ->orderByDesc('delivery_count')
            ->get();

        $latest = $base()
            ->with(['product:id,name,code,unit', 'user:id,name', 'warehouse:id,name,path'])
            ->latest('id')
            ->limit(30)
            ->get();

        return response()->json([
            'hospital' => $hospital->load('responsible:id,name'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'products' => $products,
            'representatives' => $reps,
            'latest' => $latest,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Hospital::create($this->validated($request)), 201);
    }

    public function update(Request $request, Hospital $hospital): JsonResponse
    {
        $hospital->update($this->validated($request, $hospital));

        return response()->json($hospital);
    }

    public function destroy(Hospital $hospital): JsonResponse
    {
        $units = Stock::query()->whereIn('warehouse_id', $hospital->locations()->pluck('id'))->where('quantity', '>', 0)->sum('quantity');
        if ($units > 0) {
            return response()->json(['message' => 'Hastanede konsinye ürün var. Önce ürünleri geri alın.'], 422);
        }

        // Soft delete: geçmiş teslimatlarda hastane adı görünmeye devam eder.
        $hospital->delete();

        return response()->json(['message' => 'Hastane silindi.']);
    }

    private function validated(Request $request, ?Hospital $hospital = null): array
    {
        $req = $hospital ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('hospitals', 'code')->ignore($hospital?->id)],
            'type' => ['sometimes', Rule::in(Hospital::TYPES)],
            'city' => ['nullable', 'string', Rule::in(array_values(config('provinces')))],
            // İlçe, seçilen (ya da kayıtlı) ile ait olmalı
            'responsible_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'district' => ['nullable', 'string', function ($attr, $value, $fail) use ($request, $hospital) {
                $city = $request->exists('city') ? $request->input('city') : $hospital?->city;
                if (! $city) {
                    $fail('İlçe seçmek için önce il seçin.');
                } elseif (($list = ProvinceController::districtsOf($city)) !== null && ! in_array($value, $list, true)) {
                    $fail("{$value}, {$city} iline ait bir ilçe değil.");
                }
            }],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
