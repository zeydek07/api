<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'barcode', 'category', 'brand', 'unit',
        'description', 'min_stock_level', 'is_active',
    ];

    protected $attributes = ['unit' => 'adet', 'min_stock_level' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'min_stock_level' => 'integer',
        ];
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
