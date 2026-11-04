<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockMovement extends Model
{
    public const TYPE_IN = 'in';

    public const TYPE_USAGE = 'usage';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPES = [
        self::TYPE_IN, self::TYPE_USAGE, self::TYPE_TRANSFER_OUT,
        self::TYPE_TRANSFER_IN, self::TYPE_ADJUSTMENT,
    ];

    protected $fillable = [
        'batch_uuid', 'type', 'product_id', 'warehouse_id', 'user_id', 'hospital_id',
        'quantity', 'balance_after', 'lot_number', 'expiry_date',
        'reference', 'note', 'latitude', 'longitude',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'expiry_date' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        // Hareket kayıtları denetim izidir: değiştirilemez ve silinemez.
        static::updating(fn () => throw new LogicException('Stok hareketleri değiştirilemez.'));
        static::deleting(fn () => throw new LogicException('Stok hareketleri silinemez.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
