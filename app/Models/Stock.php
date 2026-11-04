<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir depodaki bir ürünün belirli bir lot/seri/SKT'li miktarı.
 * Miktar yalnızca StockService üzerinden değişir.
 */
class Stock extends Model
{
    protected $fillable = ['warehouse_id', 'product_id', 'lot_number', 'serial_number', 'expiry_date', 'lot_key', 'quantity'];

    protected $hidden = ['lot_key'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'expiry_date' => 'date:Y-m-d',
        ];
    }

    /**
     * Lot/seri/SKT'nin tek anahtarı. Seri numaralı birim kendi seri numarasıyla,
     * diğerleri lot + SKT ile ayırt edilir. Takipsiz ürünlerde "L:|".
     */
    public static function keyFor(?string $lot, ?string $serial, ?string $expiry): string
    {
        $serial = self::clean($serial);
        if ($serial !== null) {
            return 'S:'.$serial;
        }

        return 'L:'.(self::clean($lot) ?? '').'|'.(self::clean($expiry) ?? '');
    }

    public static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
