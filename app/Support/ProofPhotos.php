<?php

namespace App\Support;

use App\Models\ProofPhoto;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Belgeye eklenen birden fazla fotoğraf: istek kuralları, çözme ve kaydetme.
 * Toplam boyut sınırı, sunucunun istek boyutu sınırının (8 MB) altında kalmak içindir;
 * mobil uygulama fotoğrafları çekerken küçültür.
 */
final class ProofPhotos
{
    /** Bir istekteki fotoğrafların toplam boyutu (çözülmüş) */
    public const TOTAL_MAX = 5 * 1024 * 1024;

    public static function rules(): array
    {
        return [
            // Eski istemciler: tek fotoğraf
            'photo' => ['nullable', 'string', 'max:8500000'],
            'photos' => ['nullable', 'array', 'max:'.ProofPhoto::MAX_PER_DOCUMENT],
            'photos.*.data' => ['required', 'string', 'max:8500000'],
            'photos.*.kind' => ['nullable', Rule::in(array_keys(ProofPhoto::KINDS))],
        ];
    }

    public static function messages(): array
    {
        return ['photos.max' => 'En fazla '.ProofPhoto::MAX_PER_DOCUMENT.' fotoğraf eklenebilir.'];
    }

    /**
     * İstekteki fotoğrafları doğrular. Kaydetmeden önce çağrılır ki hatalı istek iz bırakmasın.
     *
     * @param  int  $existing  belgede zaten olan fotoğraf sayısı
     * @return list<array{kind:string, image:array{ext:string, bytes:string}}>
     */
    public static function decode(array $data, int $existing = 0): array
    {
        $raw = $data['photos'] ?? [];
        if (! empty($data['photo'])) {
            $raw[] = ['data' => $data['photo'], 'kind' => 'other'];
        }
        if ($existing + count($raw) > ProofPhoto::MAX_PER_DOCUMENT) {
            throw ValidationException::withMessages(['photos' => 'Bir belgede en fazla '.ProofPhoto::MAX_PER_DOCUMENT.' fotoğraf olabilir.']);
        }

        $out = [];
        $total = 0;
        foreach (array_values($raw) as $i => $p) {
            $image = ProofImage::decode($p['data'] ?? null, "photos.$i", ProofImage::PHOTO_MAX);
            if (! $image) {
                continue;
            }
            $total += strlen($image['bytes']);
            $out[] = ['kind' => $p['kind'] ?? 'other', 'image' => $image];
        }
        if ($total > self::TOTAL_MAX) {
            throw ValidationException::withMessages(['photos' => 'Fotoğrafların toplam boyutu çok büyük (en fazla '.(self::TOTAL_MAX / 1048576).' MB). Daha az fotoğraf gönderin.']);
        }

        return $out;
    }

    /** Çözülmüş fotoğrafları belgenin klasörüne yazar ve kaydını açar */
    public static function store(Model $owner, string $folder, array $decoded, ?User $user): void
    {
        foreach ($decoded as $p) {
            $path = ProofImage::store("{$folder}/photo-".Str::lower((string) Str::ulid()), $p['image']);
            $owner->photos()->create([
                'kind' => $p['kind'],
                'path' => $path,
                'bytes' => strlen($p['image']['bytes']),
                'created_by' => $user?->id,
            ]);
        }
    }

    /** PDF'e gömülecek fotoğraflar: [label, data URI] */
    public static function forPdf(Model $owner, int $maxWidth = 700): array
    {
        return $owner->photos()->orderBy('id')->get()
            ->map(fn (ProofPhoto $p) => ['label' => $p->label, 'src' => ProofImage::dataUri($p->path, $maxWidth)])
            ->filter(fn ($p) => $p['src'] !== null)
            ->values()->all();
    }
}
