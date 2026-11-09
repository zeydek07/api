<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Hastaneye bağlı doktor; vakada seçilir. */
class Doctor extends Model
{
    protected $fillable = ['hospital_id', 'name', 'title', 'branch', 'phone', 'email', 'is_active', 'created_by'];

    protected $appends = ['display_name'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** "Op. Dr. Ahmet Kaya" */
    public function getDisplayNameAttribute(): string
    {
        return trim(($this->title ? $this->title.' ' : '').$this->name);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class)->withTrashed();
    }

    public function cases(): HasMany
    {
        return $this->hasMany(SurgeryCase::class);
    }
}
