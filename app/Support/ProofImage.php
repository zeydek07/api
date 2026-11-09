<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Saha belgelerindeki kanıt görselleri (imza, fotoğraf):
 * data URL çözme, gizli diske yazma ve PDF'e gömmek için data URI üretme.
 */
final class ProofImage
{
    public const DISK = 'local'; // storage/app/private — herkese açık değil

    public const SIGNATURE_MAX = 600 * 1024;

    public const PHOTO_MAX = 6 * 1024 * 1024;

    /** @return array{ext:string, bytes:string}|null */
    public static function decode(?string $dataUrl, string $field, int $maxBytes): ?array
    {
        if (! $dataUrl) {
            return null;
        }
        if (! preg_match('#^data:image/(png|jpe?g|webp);base64,(.+)$#', $dataUrl, $m)) {
            throw ValidationException::withMessages([$field => 'Görsel PNG, JPEG veya WEBP olmalı.']);
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || strlen($bytes) > $maxBytes) {
            throw ValidationException::withMessages([$field => 'Görsel okunamadı ya da çok büyük (en fazla '.round($maxBytes / 1048576, 1).' MB).']);
        }
        if (@getimagesizefromstring($bytes) === false) {
            throw ValidationException::withMessages([$field => 'Geçerli bir görsel değil.']);
        }

        return ['ext' => $m[1] === 'jpeg' ? 'jpg' : $m[1], 'bytes' => $bytes];
    }

    /** "deliveries/12/signature" + uzantı */
    public static function store(string $pathWithoutExt, array $image): string
    {
        $path = "{$pathWithoutExt}.{$image['ext']}";
        Storage::disk(self::DISK)->put($path, $image['bytes']);

        return $path;
    }

    /** PDF'e gömülecek data URI; büyük fotoğraflar küçültülür (GD varsa). */
    public static function dataUri(?string $path, int $maxWidth): ?string
    {
        if (! $path || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }
        $bytes = Storage::disk(self::DISK)->get($path);
        $mime = (getimagesizefromstring($bytes) ?: [])['mime'] ?? 'image/png';

        if (function_exists('imagecreatefromstring') && $mime !== 'image/png') {
            $src = @imagecreatefromstring($bytes);
            if ($src !== false) {
                if (imagesx($src) > $maxWidth) {
                    $src = imagescale($src, $maxWidth) ?: $src;
                }
                ob_start();
                imagejpeg($src, null, 75);
                $bytes = ob_get_clean();
                $mime = 'image/jpeg';
            }
        }

        return "data:{$mime};base64,".base64_encode($bytes);
    }
}
