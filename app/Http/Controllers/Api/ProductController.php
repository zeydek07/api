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
                ->orWhere('barcode', $request->q)
                ->orWhere('gtin', self::gtin14((string) $request->q))))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json($products);
    }

    /** Barkod ya da karekod (GTIN) okutunca ürünü bulmak için. GTIN 8-14 haneli olabilir; 14'e tamamlanır. */
    public function byBarcode(string $barcode): JsonResponse
    {
        return response()->json(
            Product::where('barcode', $barcode)->orWhere('gtin', self::gtin14($barcode))->firstOrFail()
        );
    }

    public function categories(): JsonResponse
    {
        return response()->json(Product::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'));
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['stocks' => fn ($q) => $q->where('quantity', '!=', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_date'),
            'stocks.warehouse:id,name,path,code,type,hospital_id', 'stocks.warehouse.hospital:id,name']);

        return response()->json($product);
    }

    public function store(Request $request): JsonResponse
    {
        $product = Product::create($this->validated($request));

        return response()->json($product, 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $this->validated($request, $product);
        if (isset($data['tracking']) && $data['tracking'] !== $product->tracking
            && $product->stocks()->where('quantity', '!=', 0)->exists()) {
            return response()->json(['message' => 'Stokta bulunan ürünün takip türü değiştirilemez.', 'errors' => [
                'tracking' => ['Önce bu ürünün stoğunu sıfırlayın.'],
            ]], 422);
        }
        $product->update($data);

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

    /** 8-14 haneli GTIN'i 14 haneye tamamlar (karekodda hep 14 hane okunur) */
    public static function gtin14(string $value): string
    {
        return ctype_digit($value) && strlen($value) <= 14 ? str_pad($value, 14, '0', STR_PAD_LEFT) : $value;
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $req = $product ? 'sometimes' : 'required';

        // Benzersizlik kontrolü kayıtlı (14 haneli) biçimle yapılsın
        if ($request->filled('gtin') && ctype_digit((string) $request->gtin) && strlen((string) $request->gtin) >= 8) {
            $request->merge(['gtin' => self::gtin14((string) $request->gtin)]);
        }

        return $request->validate([
            'name' => [$req, 'string', 'max:255'],
            'code' => [$req, 'string', 'max:100', Rule::unique('products', 'code')->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($product?->id)],
            // Karekoddaki ürün numarası; 8-14 hane, 14'e tamamlanarak saklanır
            'gtin' => ['nullable', 'digits_between:8,14', Rule::unique('products', 'gtin')->ignore($product?->id)],
            'tracking' => ['sometimes', Rule::in(Product::TRACKINGS)],
            'category' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'unit' => ['sometimes', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:5000'],
            'min_stock_level' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
