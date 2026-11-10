<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Vaka / teslimat belgesindeki etiketli fotoğraf. Dosya gizli diskte durur;
 * yalnızca belgeyi görebilen kullanıcı API üzerinden indirebilir. Kanıt olduğu için silinmez.
 */
class ProofPhoto extends Model
{
    public const MAX_PER_DOCUMENT = 10;

    public const KINDS = [
        'usage_form' => 'Kullanım formu',
        'product_label' => 'Ürün etiketi',
        'patient_label' => 'Hasta etiket sayfası',
        'delivery_form' => 'Teslim formu / irsaliye',
        'shelf' => 'Raf / dolap',
        'other' => 'Diğer',
    ];

    protected $fillable = ['kind', 'path', 'bytes', 'created_by'];

    protected $hidden = ['path', 'owner_type', 'owner_id'];

    protected $appends = ['label'];

    public function getLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? 'Diğer';
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
