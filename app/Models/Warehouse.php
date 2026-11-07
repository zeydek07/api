<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stokun durduğu yer: merkez depo, şube, mümessil aracı (field) ya da
 * hastanedeki konsinye lokasyonu (consignment, hospital_id dolu).
 */
class Warehouse extends Model
{
    use HasFactory;

    public const TYPE_CONSIGNMENT = 'consignment';

    /** Depolar sayfasından yönetilen türler */
    public const TYPES = ['central', 'branch', 'field'];

    public const ALL_TYPES = [...self::TYPES, self::TYPE_CONSIGNMENT];

    /**
     * Hastane içi lokasyon seviyeleri ve derinlik sırası. Alt lokasyonun sırası
     * üstündekinden büyük olmalı (bölüm › oda › dolap › raf; depo bir oda gibi).
     */
    public const LEVELS = ['bolum' => 1, 'oda' => 2, 'depo' => 2, 'dolap' => 3, 'raf' => 4];

    public const LEVEL_LABELS = ['bolum' => 'Bölüm', 'oda' => 'Oda', 'depo' => 'Depo', 'dolap' => 'Dolap', 'raf' => 'Raf'];

    public const PATH_SEPARATOR = ' › ';

    protected $fillable = ['name', 'code', 'type', 'hospital_id', 'parent_id', 'level', 'address', 'is_active'];

    protected $hidden = ['pivot'];

    protected $attributes = ['type' => 'central', 'is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Hastane lokasyonlarında tam yol saklanır: "Ortopedi › Ameliyathane 2 › Dolap A"
        static::saving(function (Warehouse $w) {
            if (! $w->isConsignment()) {
                $w->path = null;

                return;
            }
            $w->level ??= 'depo';
            $parent = $w->parent_id ? Warehouse::find($w->parent_id) : null;
            $w->path = $parent ? $parent->path.self::PATH_SEPARATOR.$w->name : $w->name;
        });

        // Adı değişince alt lokasyonların yolları da güncellenir
        static::saved(function (Warehouse $w) {
            if ($w->isConsignment() && $w->wasChanged('path')) {
                $w->children()->get()->each->save();
            }
        });
    }

    public function isConsignment(): bool
    {
        return $this->type === self::TYPE_CONSIGNMENT;
    }

    public function scopeConsignment(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_CONSIGNMENT);
    }

    /** Firma depoları (merkez, şube, araç) */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('type', '!=', self::TYPE_CONSIGNMENT);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class)->withTrashed();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'parent_id');
    }

    /**
     * Verilen lokasyonun kendisi ve tüm alt lokasyonlarının id'leri.
     *
     * @param  Collection<int, Warehouse>  $all  aynı hastanenin tüm lokasyonları
     * @return list<int>
     */
    public static function subtreeIds(int $rootId, Collection $all): array
    {
        $ids = [$rootId];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($all->where('parent_id', $ids[$i]) as $child) {
                $ids[] = $child->id;
            }
        }

        return $ids;
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
