<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Models\HospitalContact;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Hastanenin alt kayıtları: konsinye lokasyonları, hastane personeli ve konsinye stok.
 */
class HospitalStructureController extends Controller
{
    // ------------------------------------------------------------ lokasyonlar

    public function locations(Hospital $hospital): JsonResponse
    {
        return response()->json(
            $hospital->locations()
                ->withSum(['stocks as units' => fn ($q) => $q->where('quantity', '>', 0)], 'quantity')
                ->orderByDesc('is_active')->orderBy('id')
                ->get(['id', 'name', 'code', 'type', 'hospital_id', 'is_active'])
        );
    }

    public function storeLocation(Request $request, Hospital $hospital): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $this->ensureUniqueLocationName($hospital, $data['name']);

        $n = $hospital->locations()->count() + 1;
        while (Warehouse::where('code', "KNS-{$hospital->id}-{$n}")->exists()) {
            $n++;
        }

        $location = $hospital->locations()->create([
            'name' => $data['name'], 'code' => "KNS-{$hospital->id}-{$n}", 'type' => Warehouse::TYPE_CONSIGNMENT,
        ]);

        return response()->json($location, 201);
    }

    public function updateLocation(Request $request, Hospital $hospital, Warehouse $location): JsonResponse
    {
        $this->ensureBelongs($hospital, $location);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['name'])) {
            $this->ensureUniqueLocationName($hospital, $data['name'], $location->id);
        }
        if (($data['is_active'] ?? true) === false) {
            $this->ensureNotLastActive($hospital, $location);
            if ($location->stocks()->where('quantity', '>', 0)->exists()) {
                return $this->fail('is_active', 'İçinde ürün bulunan lokasyon pasife alınamaz. Önce ürünleri geri alın ya da başka lokasyona taşıyın.');
            }
        }

        $location->update($data);

        return response()->json($location);
    }

    public function destroyLocation(Hospital $hospital, Warehouse $location): JsonResponse
    {
        $this->ensureBelongs($hospital, $location);
        $this->ensureNotLastActive($hospital, $location);

        if (StockMovement::where('warehouse_id', $location->id)->exists()) {
            return $this->fail('location', 'Hareket görmüş lokasyon silinemez; pasife alabilirsiniz.');
        }

        $location->delete();

        return response()->json(['message' => 'Lokasyon silindi.']);
    }

    // ------------------------------------------------------------ hastane personeli

    public function contacts(Request $request, Hospital $hospital): JsonResponse
    {
        return response()->json(
            $hospital->contacts()
                ->with('location:id,name')
                ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
                ->orderBy('name')
                ->get()
        );
    }

    /** Teslimat sırasında "yeni kişi" olarak da eklenebilir (deliveries.create yetkisiyle) */
    public function storeContact(Request $request, Hospital $hospital): JsonResponse
    {
        $data = $this->validatedContact($request, $hospital);
        $contact = $hospital->contacts()->create([...$data, 'created_by' => $request->user()->id]);

        return response()->json($contact->load('location:id,name'), 201);
    }

    public function updateContact(Request $request, Hospital $hospital, HospitalContact $contact): JsonResponse
    {
        abort_unless($contact->hospital_id === $hospital->id, 404);
        $contact->update($this->validatedContact($request, $hospital, $contact));

        return response()->json($contact->load('location:id,name'));
    }

    public function destroyContact(Hospital $hospital, HospitalContact $contact): JsonResponse
    {
        abort_unless($contact->hospital_id === $hospital->id, 404);
        $contact->delete(); // soft delete: geçmiş teslimat belgelerinde adı kalır

        return response()->json(['message' => 'Kişi silindi.']);
    }

    // ------------------------------------------------------------ konsinye stok

    /** Hastanedeki konsinye ürünler (lot/seri/SKT ile), SKT'si yakın olan önce */
    public function consignment(Request $request, Hospital $hospital): JsonResponse
    {
        $request->validate(['location_id' => ['nullable', 'integer']]);
        $ids = $hospital->locations()->pluck('id');

        $rows = Stock::query()
            ->whereIn('warehouse_id', $ids)
            ->where('quantity', '>', 0)
            ->when($request->filled('location_id'), fn ($q) => $q->where('warehouse_id', $request->location_id))
            ->with(['product:id,name,code,gtin,tracking,unit', 'warehouse:id,name'])
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->get();

        $today = Carbon::today();
        $warn = $today->copy()->addDays(config('inventory.expiry_warning_days'));

        return response()->json([
            'summary' => [
                'units' => (float) $rows->sum('quantity'),
                'products' => $rows->pluck('product_id')->unique()->count(),
                'expiring' => (float) $rows->filter(fn ($s) => $s->expiry_date && $s->expiry_date->between($today, $warn))->sum('quantity'),
                'expired' => (float) $rows->filter(fn ($s) => $s->expiry_date && $s->expiry_date->lt($today))->sum('quantity'),
            ],
            'rows' => $rows,
        ]);
    }

    // ------------------------------------------------------------ yardımcılar

    private function validatedContact(Request $request, Hospital $hospital, ?HospitalContact $contact = null): array
    {
        $req = $contact ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'location_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')
                ->where('hospital_id', $hospital->id)->where('type', Warehouse::TYPE_CONSIGNMENT)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function ensureBelongs(Hospital $hospital, Warehouse $location): void
    {
        abort_unless($location->hospital_id === $hospital->id && $location->isConsignment(), 404);
    }

    private function ensureNotLastActive(Hospital $hospital, Warehouse $location): void
    {
        if ($location->is_active && $hospital->locations()->where('is_active', true)->count() <= 1) {
            abort($this->fail('location', 'Hastanenin en az bir aktif konsinye lokasyonu olmalı.'));
        }
    }

    private function ensureUniqueLocationName(Hospital $hospital, string $name, ?int $ignoreId = null): void
    {
        $exists = $hospital->locations()->where('name', $name)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
        if ($exists) {
            abort($this->fail('name', "Bu hastanede \"{$name}\" adlı bir lokasyon zaten var."));
        }
    }

    private function fail(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
