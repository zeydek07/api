<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HospitalController;
use App\Http\Controllers\Api\MobileController;
use App\Http\Controllers\Api\MovementController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProvinceController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
| Token'sız erişilebilen TEK uç nokta giriştir.
| Geri kalan her şey "auth:sanctum" + "active" korumasındadır.
*/
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {

    // Oturum
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutAll']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });

    // Kontrol paneli ve raporlar
    Route::get('dashboard', [ReportController::class, 'dashboard'])->middleware('permission:dashboard.view');
    Route::prefix('reports')->middleware('permission:reports.view')->group(function () {
        Route::get('usage', [ReportController::class, 'usage']);
        Route::get('low-stock', [ReportController::class, 'lowStock']);
    });

    // Personeller
    Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
    Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
    Route::post('users/{user}/revoke-tokens', [UserController::class, 'revokeTokens'])->middleware('permission:users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');

    // Roller ve sayfa yetkileri
    Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view');
    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view|users.view');
    Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');

    // Ürünler
    Route::get('products', [ProductController::class, 'index'])->middleware('permission:products.view');
    Route::get('products/categories', [ProductController::class, 'categories'])->middleware('permission:products.view');
    Route::get('products/barcode/{barcode}', [ProductController::class, 'byBarcode'])->middleware('permission:products.view');
    Route::get('products/{product}', [ProductController::class, 'show'])->middleware('permission:products.view');
    Route::post('products', [ProductController::class, 'store'])->middleware('permission:products.create');
    Route::put('products/{product}', [ProductController::class, 'update'])->middleware('permission:products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('permission:products.delete');

    // 81 il ve 973 ilçe (hastane formu ve mobil uygulama için)
    Route::get('provinces', [ProvinceController::class, 'index']);
    Route::get('provinces/{code}/districts', [ProvinceController::class, 'districts'])->whereNumber('code');

    // Hastaneler
    Route::get('hospitals', [HospitalController::class, 'index'])->middleware('permission:hospitals.view');
    Route::get('hospitals/cities', [HospitalController::class, 'cities'])->middleware('permission:hospitals.view');
    Route::get('hospitals/{hospital}', [HospitalController::class, 'show'])->middleware('permission:hospitals.view');
    Route::post('hospitals', [HospitalController::class, 'store'])->middleware('permission:hospitals.create');
    Route::put('hospitals/{hospital}', [HospitalController::class, 'update'])->middleware('permission:hospitals.update');
    Route::delete('hospitals/{hospital}', [HospitalController::class, 'destroy'])->middleware('permission:hospitals.delete');

    // Depolar
    Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('permission:warehouses.view');
    Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])->middleware('permission:warehouses.view');
    Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('permission:warehouses.create');
    Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware('permission:warehouses.update');
    Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->middleware('permission:warehouses.delete');

    // Stok
    Route::get('stocks', [StockController::class, 'index'])->middleware('permission:stock.view');
    Route::post('stocks/receive', [StockController::class, 'receive'])->middleware('permission:stock.receive');
    Route::post('stocks/transfer', [StockController::class, 'transfer'])->middleware('permission:stock.transfer');
    Route::post('stocks/adjust', [StockController::class, 'adjust'])->middleware('permission:stock.adjust');

    // Stok hareketleri (kim ne kullandı)
    Route::get('movements', [MovementController::class, 'index'])
        ->middleware('permission:movements.view_team|movements.view_all');

    // Mobil saha uygulaması
    Route::prefix('mobile')->middleware('permission:usage.create')->group(function () {
        Route::get('warehouses', [MobileController::class, 'warehouses']);
        Route::get('warehouses/{warehouse}/stock', [MobileController::class, 'warehouseStock']);
        Route::get('hospitals', [MobileController::class, 'hospitals']);
        Route::post('usages', [MobileController::class, 'storeUsage'])->middleware('throttle:usage');
        Route::get('usages', [MobileController::class, 'myUsages']);
    });
});

// Tanımsız her API adresi JSON 404 döner.
Route::fallback(fn () => response()->json(['message' => 'Adres bulunamadı.'], 404));
