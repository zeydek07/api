<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ProvinceController extends Controller
{
    /** 81 il, Türkçe alfabe sırasıyla: [{code: 34, name: "İstanbul"}, ...] */
    public function index(): JsonResponse
    {
        return response()->json(self::sorted());
    }

    /** Bir ilin ilçeleri (Türkçe alfabe sırasıyla): ["Adalar", "Arnavutköy", ...] */
    public function districts(int $code): JsonResponse
    {
        abort_unless(isset(config('districts')[$code]), 404);

        return response()->json(config('districts')[$code]);
    }

    /** İl adına göre ilçe listesi; il bilinmiyorsa null */
    public static function districtsOf(?string $province): ?array
    {
        $code = array_search($province, config('provinces'), true);

        return $code === false ? null : config('districts')[$code];
    }

    /** @return array<int, array{code:int, name:string}> */
    public static function sorted(): array
    {
        $list = collect(config('provinces'))->map(fn ($name, $code) => ['code' => $code, 'name' => $name])->values()->all();

        $collator = class_exists(\Collator::class) ? new \Collator('tr_TR') : null;
        usort($list, fn ($a, $b) => $collator ? $collator->compare($a['name'], $b['name']) : strcmp($a['name'], $b['name']));

        return $list;
    }
}
