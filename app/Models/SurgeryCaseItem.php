<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vakadaki ürün satırı.
 * used: kullanıldı · opened: açıldı, kullanılmadı (satılamaz) — ikisi de stoktan düşer ve faturalanır.
 * unused: salona götürüldü, açılmadan geri kondu — stok değişmez.
 */
class SurgeryCaseItem extends Model
{
    public const USED = 'used';

    public const OPENED = 'opened';

    public const UNUSED = 'unused';

    public const STATUSES = [self::USED, self::OPENED, self::UNUSED];

    /** Stoktan düşen ve faturalanan durumlar */
    public const BILLABLE = [self::USED, self::OPENED];

    protected $fillable = [
        'surgery_case_id', 'product_id', 'warehouse_id', 'status', 'quantity',
        'lot_number', 'serial_number', 'expiry_date', 'expired_at_use',
        'invoice_number', 'invoice_date', 'invoiced_at', 'invoiced_by',
    ];

    protected $appends = ['billable'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'expiry_date' => 'date:Y-m-d',
            'invoice_date' => 'date:Y-m-d',
            'invoiced_at' => 'datetime',
            'expired_at_use' => 'boolean',
        ];
    }

    public function getBillableAttribute(): bool
    {
        return in_array($this->status, self::BILLABLE, true);
    }

    public function surgeryCase(): BelongsTo
    {
        return $this->belongsTo(SurgeryCase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function invoicer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invoiced_by')->withTrashed();
    }
}
