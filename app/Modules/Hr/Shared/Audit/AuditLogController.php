<?php

namespace App\Modules\Hr\Shared\Audit;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = AuditLog::query()
            ->when(isset($f['subject_type']), fn ($q) => $q->where('subject_type', $f['subject_type']))
            ->when(isset($f['subject_id']), fn ($q) => $q->where('subject_id', $f['subject_id']))
            ->when(isset($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(isset($f['action']), fn ($q) => $q->where('action', $f['action']))
            ->when(isset($f['from']), fn ($q) => $q->where('created_at', '>=', $f['from']))
            ->when(isset($f['to']), fn ($q) => $q->where('created_at', '<=', $f['to'].(strlen($f['to']) <= 10 ? ' 23:59:59' : '')))
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json($logs);
    }
}
