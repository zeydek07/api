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

    /**
     * Lokasyon ağacı, tam yola göre sıralı. units: lokasyonun kendi stoku,
     * total_units: alt lokasyonlarla birlikte.
     */
    public function locations(Hospital $hospital): JsonResponse
    {
        $all = $hospital->locations()
            ->withSum(['stocks as units' => fn ($q) => $q->where('quantity', '>', 0)], 'quantity')
            ->get(['id', 'name', 'path', 'code', 'type', 'level', 'parent_id', 'hospital_id', 'is_active']);

        $rows = $all->map(function (Warehouse $l) use ($all) {
            $ids = Warehouse::subtreeIds($l->id, $all);
            $l->setAttribute('depth', substr_count((string) $l->path, Warehouse::PATH_SEPARATOR));
            $l->setAttribute('total_units', (float) $all->whereIn('id', $ids)->sum('units'));
            $l->setAttribute('has_children', count($ids) > 1);

            return $l;
        })->sortBy(fn ($l) => mb_strtolower((string) $l->path), SORT_NATURAL)->values();

        return response()->json($rows);
    }

    public function storeLocation(Request $request, Hospital $hospital): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'level' => ['required', Rule::in(array_keys(Warehouse::LEVELS))],
            'parent_id' => ['nullable', 'integer'],
        ], [], ['level' => 'seviye', 'parent_id' => 'üst lokasyon']);

        $parent = null;
        if ($data['parent_id'] ?? null) {
            $parent = $hospital->locations()->find($data['parent_id']);
            if (! $parent) {
                return $this->fail('parent_id', 'Üst lokasyon bu hastaneye ait değil.');
            }
            if (! $parent->is_active) {
                return $this->fail('parent_id', 'Pasif lokasyonun altına yeni lokasyon eklenemez.');
            }
            $parentLevel = $parent->level ?? 'depo';
            if (Warehouse::LEVELS[$data['level']] <= Warehouse::LEVELS[$parentLevel]) {
                return $this->fail('level', Warehouse::LEVEL_LABELS[$parentLevel].' altına '
                    .mb_strtolower(Warehouse::LEVEL_LABELS[$data['level']]).' eklenemez. Sıra: bölüm › oda / depo › dolap › raf.');
            }
        }
        $this->ensureUniqueLocationName($hospital, $data['name'], $parent?->id);

        $n = $hospital->locations()->count() + 1;
        while (Warehouse::where('code', "KNS-{$hospital->id}-{$n}")->exists()) {
            $n++;
        }

        $location = $hospital->locations()->create([
            'name' => $data['name'], 'code' => "KNS-{$hospital->id}-{$n}", 'type' => Warehouse::TYPE_CONSIGNMENT,
            'level' => $data['level'], 'parent_id' => $parent?->id,
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
            $this->ensureUniqueLocationName($hospital, $data['name'], $location->parent_id, $location->id);
        }
        if (($data['is_active'] ?? true) === false) {
            $this->ensureNotLastActive($hospital, $location);
            if ($location->children()->where('is_active', true)->exists()) {
                return $this->fail('is_active', 'Altında aktif lokasyon bulunan lokasyon pasife alınamaz. Önce alt lokasyonları pasife alın.');
            }
            if ($location->stocks()->where('quantity', '>', 0)->exists()) {
                return $this->fail('is_active', 'İçinde ürün bulunan lokasyon pasife alınamaz. Önce ürünleri geri alın ya da başka lokasyona taşıyın.');
            }
        }
        if (($data['is_active'] ?? false) === true && $location->parent && ! $location->parent->is_active) {
            return $this->fail('is_active', 'Üst lokasyonu pasif olan lokasyon aktifleştirilemez.');
        }

        $location->update($data);

        return response()->json($location);
    }

    public function destroyLocation(Hospital $hospital, Warehouse $location): JsonResponse
    {
        $this->ensureBelongs($hospital, $location);
        $this->ensureNotLastActive($hospital, $location);

        if ($location->children()->exists()) {
            return $this->fail('location', 'Alt lokasyonu olan lokasyon silinemez.');
        }
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
                ->with('location:id,name,path')
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

        return response()->json($contact->load('location:id,name,path'), 201);
    }

    public function updateContact(Request $request, Hospital $hospital, HospitalContact $contact): JsonResponse
    {
        abort_unless($contact->hospital_id === $hospital->id, 404);
        $contact->update($this->validatedContact($request, $hospital, $contact));

        return response()->json($contact->load('location:id,name,path'));
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
        $all = $hospital->locations()->get(['id', 'parent_id']);
        // Bir lokasyon seçilirse alt lokasyonlarındaki (dolap, raf) ürünler de gelir
        $ids = $request->filled('location_id') ? Warehouse::subtreeIds((int) $request->location_id, $all) : $all->pluck('id')->all();

        $rows = Stock::query()
            ->whereIn('warehouse_id', $ids)
            ->where('quantity', '>', 0)
            ->with(['product:id,name,code,gtin,tracking,unit', 'warehouse:id,name,path'])
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

    /** Aynı üst lokasyonun altında aynı ad iki kez olamaz */
    private function ensureUniqueLocationName(Hospital $hospital, string $name, ?int $parentId, ?int $ignoreId = null): void
    {
        $exists = $hospital->locations()->where('name', $name)
            ->when($parentId, fn ($q) => $q->where('parent_id', $parentId), fn ($q) => $q->whereNull('parent_id'))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($exists) {
            abort($this->fail('name', "Bu seviyede \"{$name}\" adlı bir lokasyon zaten var."));
        }
    }

    private function fail(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
