<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Vaka (ameliyat).
 * planned: takvime girildi · recorded: ürünler kaydedildi, onay bekliyor ·
 * approved: onaylandı, satırları faturalanabilir · cancelled: iptal.
 * Fatura durumu satır bazındadır (kısmi faturalama olabilir).
 */
class SurgeryCase extends Model
{
    public const PLANNED = 'planned';

    public const RECORDED = 'recorded';

    public const APPROVED = 'approved';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PLANNED, self::RECORDED, self::APPROVED, self::CANCELLED];

    protected $fillable = [
        'number', 'status', 'is_unplanned', 'hospital_id', 'location_id', 'doctor_id', 'user_id', 'created_by',
        'scheduled_at', 'procedure', 'protocol_no', 'note',
        'performed_at', 'recorded_by', 'device_time', 'contact_id', 'signer_name', 'signer_title',
        'signature_path', 'photo_path', 'latitude', 'longitude', 'location_accuracy', 'location_error', 'batch_uuid',
        'email_to', 'email_status', 'emailed_at', 'email_error',
        'approved_at', 'approved_by', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected $hidden = ['signature_path', 'photo_path'];

    protected $appends = ['has_signature', 'is_overdue'];

    protected function casts(): array
    {
        return [
            'is_unplanned' => 'boolean',
            'scheduled_at' => 'datetime',
            'performed_at' => 'datetime',
            'device_time' => 'datetime',
            'emailed_at' => 'datetime',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function getHasSignatureAttribute(): bool
    {
        return (bool) $this->signature_path;
    }

    /** Saati geçti ama ürünleri kaydedilmedi: faturalanamaz, en kritik bekleyen iş */
    public function getIsOverdueAttribute(): bool
    {
        return $this->status === self::PLANNED && $this->scheduled_at?->isPast();
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class)->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'location_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(HospitalContact::class, 'contact_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(SurgeryCaseItem::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Belgeye eklenen fotoğraflar (kullanım formu, ürün etiketi...) */
    public function photos(): MorphMany
    {
        return $this->morphMany(ProofPhoto::class, 'owner')->orderBy('id');
    }

    /** Vaka geçmişi */
    public function events(): HasMany
    {
        return $this->hasMany(SurgeryCaseEvent::class)->orderBy('id');
    }
}
