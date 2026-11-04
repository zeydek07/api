<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Hospital extends Model
{
    use SoftDeletes;

    /** Devlet, Üniversite, Özel, Eğitim ve araştırma, Şehir, Diğer */
    public const TYPES = ['public', 'university', 'private', 'training_research', 'city', 'other'];

    protected $fillable = [
        'name', 'code', 'type', 'responsible_user_id', 'city', 'district', 'address',
        'notes', 'is_active',
    ];

    protected $attributes = ['type' => 'public', 'is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Her hastane en az bir konsinye lokasyonuyla başlar
        static::created(function (Hospital $hospital) {
            $hospital->locations()->create([
                'name' => 'Ana konsinye deposu',
                'code' => 'KNS-'.$hospital->id.'-1',
                'type' => Warehouse::TYPE_CONSIGNMENT,
            ]);
        });
    }

    /** Konsinye lokasyonları (hastanede ürünlerin durduğu yerler) */
    public function locations(): HasMany
    {
        return $this->hasMany(Warehouse::class)->where('type', Warehouse::TYPE_CONSIGNMENT);
    }

    /** Hastane personeli (teslim alan / muhatap kişiler) */
    public function contacts(): HasMany
    {
        return $this->hasMany(HospitalContact::class);
    }

    /** Hastaneye bakan firma personeli (mümessil) */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id')->withTrashed();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
