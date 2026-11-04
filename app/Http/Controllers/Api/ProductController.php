<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->withSum('stocks as total_stock', 'quantity')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->q.'%')
                ->orWhere('code', 'like', '%'.$request->q.'%')
                ->orWhere('barcode', $request->q)))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json($products);
    }

    /** Mobilde barkod okutunca ürünü bulmak için */
    public function byBarcode(string $barcode): JsonResponse
    {
        return response()->json(Product::where('barcode', $barcode)->firstOrFail());
    }

    public function categories(): JsonResponse
    {
        return response()->json(Product::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'));
    }

    public function show(Product $product): JsonResponse
    {
        $product->load('stocks.warehouse:id,name,code,type');

        return response()->json($product);
    }

    public function store(Request $request): JsonResponse
    {
        $product = Product::create($this->validated($request));

        return response()->json($product, 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $product->update($this->validated($request, $product));

        return response()->json($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        if ($product->stocks()->where('quantity', '>', 0)->exists()) {
            return response()->json(['message' => 'Stokta bulunan ürün silinemez. Önce stoğu sıfırlayın ya da ürünü pasife alın.'], 422);
        }

        $product->delete(); // soft delete: geçmiş hareketlerde ürün adı görünmeye devam eder

        return response()->json(['message' => 'Ürün silindi.']);
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $req = $product ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:255'],
            'code' => [$req, 'string', 'max:100', Rule::unique('products', 'code')->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($product?->id)],
            'category' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'unit' => ['sometimes', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:5000'],
            'min_stock_level' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
