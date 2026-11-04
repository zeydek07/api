<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    protected $fillable = ['name', 'code', 'type', 'hospital_id', 'address', 'is_active'];

    protected $hidden = ['pivot'];

    protected $attributes = ['type' => 'central', 'is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
