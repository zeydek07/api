<?php

namespace App\Modules\Hr\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class MeController extends Controller
{
    public function show(): JsonResponse
    {
        // Personel kaydı bağlanınca (sonraki görev) dolacak.
        return response()->json(['data' => ['employee' => null]]);
    }
}
