<?php

use App\Modules\Hr\Employees\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

Route::get('me', [MeController::class, 'show'])->middleware('permission:hr_self.view');
