<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Hospital extends Model
{
    use SoftDeletes;

    /** Devlet, Üniversite, Özel, Eğitim ve araştırma, Şehir, Diğer */
    public const TYPES = ['public', 'university', 'private', 'training_research', 'city', 'other'];

    protected $fillable = [
        'name', 'code', 'type', 'city', 'district', 'address',
        'notes', 'is_active',
    ];

    protected $attributes = ['type' => 'public', 'is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
