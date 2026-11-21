<?php

namespace App\Modules\Hr\Shared\Sequences;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Satır kilidiyle boşluksuz numara üretir; dış transaction geri alınırsa numara da geri döner. */
class SequenceGenerator
{
    /**
     * Sayaç satırını yoksa oluşturur, varsa değerine dokunmaz. Transaction dışında çağrılır.
     * Her modül kendi anahtarlarını migration'da bununla açar; böylece normal trafikte
     * eşzamanlı ilk ekleme (ve MySQL deadlock'u) yaşanmaz.
     */
    public static function ensure(string $key): void
    {
        DB::table('hr_sequences')->insertOrIgnore(['key' => $key, 'next_value' => 1]);
    }

    public static function next(string $key, string $format): string
    {
        if (sprintf($format, 1) === sprintf($format, 2)) {
            throw new InvalidArgumentException("Numara biçimi sayı içermiyor: {$format}");
        }

        // Satır zaten varsa INSERT IGNORE'un paylaşımlı kilidi (ve olası deadlock) hiç alınmaz.
        if (! DB::table('hr_sequences')->where('key', $key)->exists()) {
            self::ensure($key);
        }

        $allocate = function () use ($key): int {
            $value = (int) DB::table('hr_sequences')->where('key', $key)->lockForUpdate()->value('next_value');
            DB::table('hr_sequences')->where('key', $key)->update(['next_value' => $value + 1]);

            return $value;
        };

        $value = DB::transactionLevel() > 0 ? $allocate() : DB::transaction($allocate, 3);

        return sprintf($format, $value);
    }
}
