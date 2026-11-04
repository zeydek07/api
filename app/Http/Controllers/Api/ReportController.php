<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /** Kontrol paneli özeti */
    public function dashboard(Request $request): JsonResponse
    {
        $today = now()->startOfDay();
        $usage = MovementController::scoped($request->user())->where('type', StockMovement::TYPE_USAGE);

        return response()->json([
            'active_products' => Product::where('is_active', true)->count(),
            'low_stock_count' => $this->lowStockQuery()->count(),
            'usage_today' => (clone $usage)->where('created_at', '>=', $today)->count(),
            'usage_last_7_days' => (clone $usage)->where('created_at', '>=', now()->subDays(7))->count(),
            'latest_usages' => (clone $usage)->with(['product:id,name,unit', 'user:id,name', 'warehouse:id,name', 'hospital:id,name'])
                ->latest('id')->limit(10)->get(),
            'daily_usage' => $this->dailyUsage(clone $usage, 14),
            'top_products' => (clone $usage)->toBase()
                ->where('stock_movements.created_at', '>=', now()->subDays(30)->startOfDay())
                ->join('products', 'products.id', '=', 'stock_movements.product_id')
                ->groupBy('products.id', 'products.name', 'products.unit')
                ->select('products.id', 'products.name', 'products.unit')
                ->selectRaw('SUM(-stock_movements.quantity) as total_quantity')
                ->orderByDesc('total_quantity')
                ->limit(5)
                ->get(),
        ]);
    }

    /** Son $days günün kullanım kayıt sayısı; boş günler 0 olarak döner. */
    private function dailyUsage($usage, int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = $usage->toBase()
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as line_count')
            ->groupByRaw('DATE(created_at)')
            ->pluck('line_count', 'day');

        $result = [];
        for ($d = $from->copy(); $d->lte(now()); $d->addDay()) {
            $key = $d->toDateString();
            $result[] = ['date' => $key, 'count' => (int) ($rows[$key] ?? 0)];
        }

        return $result;
    }

    /**
     * Kullanım özeti: kim hangi üründen ne kadar kullandı.
     * group_by=user | product | user_product
     */
    public function usage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'group_by' => ['nullable', 'in:user,product,user_product,hospital,hospital_product'],
            'user_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'hospital_id' => ['nullable', 'integer'],
        ]);

        $from = $request->filled('from') ? $request->date('from')->startOfDay() : now()->startOfMonth();
        $to = $request->filled('to') ? $request->date('to')->endOfDay() : now()->endOfDay();
        $groupBy = $data['group_by'] ?? 'user_product';

        $query = MovementController::scoped($request->user())
            ->toBase()
            ->where('stock_movements.type', StockMovement::TYPE_USAGE)
            ->whereBetween('stock_movements.created_at', [$from, $to])
            ->when($request->filled('user_id'), fn ($q) => $q->where('stock_movements.user_id', $request->user_id))
            ->when($request->filled('product_id'), fn ($q) => $q->where('stock_movements.product_id', $request->product_id))
            ->when($request->filled('hospital_id'), fn ($q) => $q->where('stock_movements.hospital_id', $request->hospital_id))
            ->when(str_starts_with($groupBy, 'hospital'), fn ($q) => $q->whereNotNull('stock_movements.hospital_id'))
            ->leftJoin('hospitals', 'hospitals.id', '=', 'stock_movements.hospital_id')
            ->join('users', 'users.id', '=', 'stock_movements.user_id')
            ->join('products', 'products.id', '=', 'stock_movements.product_id');

        $columns = match ($groupBy) {
            'user' => ['users.id as user_id', 'users.name as user_name'],
            'product' => ['products.id as product_id', 'products.name as product_name', 'products.code', 'products.unit'],
            'hospital' => ['hospitals.id as hospital_id', 'hospitals.name as hospital_name', 'hospitals.city'],
            'hospital_product' => ['hospitals.id as hospital_id', 'hospitals.name as hospital_name', 'hospitals.city',
                'products.id as product_id', 'products.name as product_name', 'products.code', 'products.unit'],
            default => ['users.id as user_id', 'users.name as user_name', 'products.id as product_id',
                'products.name as product_name', 'products.code', 'products.unit'],
        };
        $groupColumns = array_map(fn ($c) => explode(' as ', $c)[0], $columns);

        $rows = $query
            ->select($columns)
            ->selectRaw('SUM(-stock_movements.quantity) as total_quantity')
            ->selectRaw('COUNT(*) as line_count')
            ->groupBy($groupColumns)
            ->orderByDesc('total_quantity')
            ->get();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'group_by' => $groupBy,
            'rows' => $rows,
        ]);
    }

    /** Minimum stok seviyesinin altına düşen ürünler (tüm depolar toplamı) */
    public function lowStock(): JsonResponse
    {
        return response()->json($this->lowStockQuery()->get());
    }

    private function lowStockQuery()
    {
        $totals = Stock::query()
            ->select('product_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('product_id');

        return Product::query()
            ->where('is_active', true)
            ->where('min_stock_level', '>', 0)
            ->leftJoinSub($totals, 't', 't.product_id', '=', 'products.id')
            ->whereRaw('COALESCE(t.total, 0) <= products.min_stock_level')
            ->select('products.id', 'products.name', 'products.code', 'products.unit', 'products.min_stock_level')
            ->selectRaw('COALESCE(t.total, 0) as total_stock')
            ->orderBy('products.name');
    }
}
