<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * GTIN-8/12/13/14 kontrol hanesi (GS1 mod-10). Mobil ve paneldeki karekod okuyucu
 * kontrol hanesi tutmayan GTIN'i reddettiği için, böyle bir ürün kartı hiç okutulamazdı.
 */
class Gtin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;
        if (! preg_match('/^\d{8,14}$/', $value)) {
            return; // biçim hatasını digits_between bildirir
        }

        if (! self::valid($value)) {
            $fail('GTIN kontrol hanesi tutmuyor. Ürün etiketindeki numarayı kontrol edin.');
        }
    }

    /** Sağdan itibaren 3,1,3,1… ağırlıklı toplam; son hane bunu 10'un katına tamamlar */
    public static function valid(string $digits): bool
    {
        $body = strrev(substr($digits, 0, -1));
        $sum = 0;
        foreach (str_split($body) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) substr($digits, -1);
    }
}
