<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Hastane personeli: teslim alan ya da muhatap kişi (firma personeli değildir). */
class HospitalContact extends Model
{
    use SoftDeletes;

    protected $fillable = ['hospital_id', 'location_id', 'name', 'title', 'phone', 'email', 'is_active', 'created_by'];

    protected $attributes = ['is_active' => true];

    protected $hidden = ['deleted_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'location_id');
    }
}
