<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use HasFactory;

    public const TYPES = ['central', 'branch', 'field'];

    protected $fillable = ['name', 'code', 'type', 'address', 'is_active'];

    protected $hidden = ['pivot'];

    protected $attributes = ['type' => 'central', 'is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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
