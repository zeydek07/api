<?php

use App\Modules\Hr\Employees\Http\Controllers\MeController;
use App\Modules\Hr\Shared\Audit\AuditLogController;
use Illuminate\Support\Facades\Route;

Route::get('me', [MeController::class, 'show'])->middleware('permission:hr_self.view');
Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:hr_audit.view');
