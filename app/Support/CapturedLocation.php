<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Saha işleminin yapıldığı andaki konum.
 *
 * İstek ya koordinat (latitude + longitude, isteğe bağlı accuracy) ya da konumun neden
 * alınamadığını (location_error) taşımak zorundadır. İkisi birden gönderilemez.
 */
final class CapturedLocation
{
    /** Cihazın bildirebileceği nedenler */
    public const ERRORS = ['denied', 'unavailable', 'timeout', 'unsupported'];

    /** Bu kural yürürlüğe girmeden önceki konumsuz kayıtlar */
    public const NOT_RECORDED = 'not_recorded';

    public const LABELS = [
        'denied' => 'Konum izni verilmedi',
        'unavailable' => 'Konum bulunamadı',
        'timeout' => 'Konum zaman aşımı',
        'unsupported' => 'Cihaz konum desteklemiyor',
        'not_recorded' => 'Konum kaydedilmemiş (eski kayıt)',
    ];

    public static function rules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'location_accuracy' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'location_error' => ['nullable', 'required_without:latitude', Rule::in(self::ERRORS), 'prohibits:latitude,longitude'],
        ];
    }

    public static function messages(): array
    {
        return [
            'location_error.required_without' => 'İşlem anındaki konum gönderilmeli; alınamadıysa nedeni (location_error) belirtilmeli.',
            'location_error.prohibits' => 'Konum alındıysa location_error gönderilmemeli.',
            'location_error.in' => 'location_error şunlardan biri olmalı: '.implode(', ', self::ERRORS).'.',
        ];
    }

    /** Doğrulanmış veriden kayda yazılacak alanlar */
    public static function attributes(array $data): array
    {
        $hasPosition = isset($data['latitude'], $data['longitude']);

        return [
            'latitude' => $hasPosition ? $data['latitude'] : null,
            'longitude' => $hasPosition ? $data['longitude'] : null,
            'location_accuracy' => $hasPosition && isset($data['location_accuracy']) ? (int) round($data['location_accuracy']) : null,
            'location_error' => $hasPosition ? null : ($data['location_error'] ?? null),
        ];
    }
}
