<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Konsinye teslimat belgesi.
 * delivery: araçtan hastane lokasyonuna bırakma · return: hastaneden araca geri alma.
 * Belge tamamlandıktan sonra değiştirilemez; yalnızca iptal edilebilir.
 */
class Delivery extends Model
{
    public const TYPE_DELIVERY = 'delivery';

    public const TYPE_RETURN = 'return';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const EMAIL_SENT = 'sent';

    public const EMAIL_FAILED = 'failed';

    protected $fillable = [
        'number', 'type', 'status', 'hospital_id', 'location_id', 'vehicle_id', 'contact_id',
        'receiver_name', 'receiver_title', 'user_id', 'delivered_at', 'device_time',
        'latitude', 'longitude', 'location_accuracy', 'location_error', 'signature_path', 'photo_path', 'note', 'batch_uuid',
        'email_to', 'email_status', 'emailed_at', 'email_error',
        'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected $hidden = ['signature_path', 'photo_path'];

    protected $appends = ['has_signature', 'location_status'];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'device_time' => 'datetime',
            'cancelled_at' => 'datetime',
            'emailed_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function getHasSignatureAttribute(): bool
    {
        return (bool) $this->signature_path;
    }

    /** ok: işlem anında konum alındı · missing: alınamadı (nedeni location_error'da) */
    public function getLocationStatusAttribute(): string
    {
        return $this->latitude !== null ? 'ok' : 'missing';
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class)->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'location_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'vehicle_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(HospitalContact::class, 'contact_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Belgedeki ürün satırları: varış tarafındaki (+) hareketler */
    public function lines(): HasMany
    {
        return $this->movements()->whereIn('type', [StockMovement::TYPE_CONSIGN_IN, StockMovement::TYPE_RETURN_IN]);
    }

    /** Belgeye eklenen fotoğraflar (kullanım formu, ürün etiketi...) */
    public function photos(): MorphMany
    {
        return $this->morphMany(ProofPhoto::class, 'owner')->orderBy('id');
    }
}
