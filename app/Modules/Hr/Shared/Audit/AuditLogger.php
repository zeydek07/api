<?php

namespace App\Modules\Hr\Shared\Audit;

use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * Çağıranın iş transaction'ı içinde çağrılır; satır onunla birlikte commit/rollback olur.
     *
     * @param  list<string>  $changedFields  Yalnızca alan adları; değer asla yazılmaz.
     */
    public static function record(
        string $action,
        string $subjectType,
        int $subjectId,
        ?int $employeeId = null,
        array $changedFields = [],
        string $channel = 'panel',
    ): AuditLog {
        $request = request();

        if (! $request->attributes->has('hr_request_id')) {
            $request->attributes->set('hr_request_id', (string) Str::uuid());
        }

        return AuditLog::create([
            'actor_user_id' => auth()->id(),
            'actor_channel' => $channel,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'employee_id' => $employeeId,
            'changed_fields' => array_values(array_map('strval', $changedFields)),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent() ? mb_substr($request->userAgent(), 0, 255) : null,
            'request_id' => $request->attributes->get('hr_request_id'),
        ]);
    }
}
