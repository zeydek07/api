<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vaka geçmişindeki bir adım (planlandı, kaydedildi, devredildi, fotoğraf eklendi...) */
class SurgeryCaseEvent extends Model
{
    public const UPDATED_AT = null;

    public const LABELS = [
        'planned' => 'Planlandı',
        'updated' => 'Plan değiştirildi',
        'recorded' => 'Ürünler kaydedildi',
        'unplanned' => 'Plansız (acil) vaka kaydedildi',
        'approved' => 'Onaylandı',
        'cancelled' => 'İptal edildi',
        'transferred' => 'Devredildi',
        'photo_added' => 'Fotoğraf eklendi',
    ];

    protected $fillable = ['user_id', 'type', 'data'];

    protected $appends = ['label'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function getLabelAttribute(): string
    {
        return self::LABELS[$this->type] ?? $this->type;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
